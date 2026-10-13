<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\Net32\Net32Stores;
use App\Models\InventoryModel;
use CodeIgniter\CLI\CLI;

class InventoryQuantitySyncService
{
    public const ALL_SHEETS = '*';

    private bool $showProgress = false;

    public function __construct(
        private readonly InventoryModel $inventory,
        private readonly InventoryQuantityCheckService $quantityCheck,
    ) {
    }

    /**
     * Pull Net32 quantities for every inventory row on a sheet tab.
     *
     * @return array{
     *     sheet_name: string,
     *     total: int,
     *     processed: int,
     *     updated: int,
     *     unchanged: int,
     *     missing: int,
     *     errors: list<string>,
     *     row_total?: int
     * }
     */
    public function syncSheetFromNet32(
        string $sheetName,
        ?float $delaySeconds = null,
        bool $verbose = true,
        ?int $jobId = null,
        ?int $logId = null,
        ?int $pipelineImportJobId = null,
    ): array {
        $sheetName = trim($sheetName);
        $rows      = $this->inventory->findBySheetName($sheetName);

        return $this->syncRowsFromNet32(
            $rows,
            $sheetName,
            $delaySeconds,
            $verbose,
            $jobId,
            $logId,
            $pipelineImportJobId,
        );
    }

    /**
     * Pull Net32 quantities for every inventory row across all sheet tabs.
     *
     * @return array{
     *     sheet_name: string,
     *     total: int,
     *     processed: int,
     *     updated: int,
     *     unchanged: int,
     *     missing: int,
     *     errors: list<string>,
     *     row_total?: int
     * }
     */
    public function syncAllFromNet32(
        ?float $delaySeconds = null,
        bool $verbose = true,
        ?int $jobId = null,
        ?int $logId = null,
        ?int $pipelineImportJobId = null,
    ): array {
        $rows = $this->inventory->findAllForQuantitySync();

        return $this->syncRowsFromNet32(
            $rows,
            self::ALL_SHEETS,
            $delaySeconds,
            $verbose,
            $jobId,
            $logId,
            $pipelineImportJobId,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{
     *     sheet_name: string,
     *     total: int,
     *     processed: int,
     *     updated: int,
     *     unchanged: int,
     *     missing: int,
     *     errors: list<string>,
     *     row_total: int
     * }
     */
    private function syncRowsFromNet32(
        array $rows,
        string $scope,
        ?float $delaySeconds = null,
        bool $verbose = true,
        ?int $jobId = null,
        ?int $logId = null,
        ?int $pipelineImportJobId = null,
    ): array {
        $this->showProgress = $verbose && is_cli();
        $scopeLabel         = $this->formatScopeLabel($scope);
        $groups             = $this->groupRowsBySku($rows);
        $total              = count($groups);
        $rowTotal           = count($rows);
        $processed          = 0;
        $updated            = 0;
        $unchanged          = 0;
        $missing            = 0;
        $errors             = [];
        $stores             = config('Net32')->configuredStoreIds();

        $delaySeconds = max(0.0, $delaySeconds ?? 0.25);
        $storeLabel   = implode(', ', array_map(
            static fn (string $store): string => Net32Stores::label($store),
            $stores,
        ));

        if ($this->showProgress) {
            CLI::write(sprintf(
                '%s: checking %d unique SKU(s) across %d row(s) against %s...',
                $scopeLabel,
                $total,
                $rowTotal,
                $storeLabel !== '' ? $storeLabel : 'Net32',
            ), 'cyan');
        }

        $this->reportProgress(
            $jobId,
            $logId,
            $pipelineImportJobId,
            0,
            $total,
            $scope,
            sprintf(
                'Checking %s for %d unique SKU(s) / %d row(s) (%s)...',
                $storeLabel !== '' ? $storeLabel : 'Net32',
                $total,
                $rowTotal,
                $scopeLabel,
            ),
        );

        foreach ($groups as $group) {
            if ($this->isSyncCancelled($jobId, $pipelineImportJobId)) {
                return $this->cancelledResult($scope, $total, $processed, $updated, $unchanged, $missing, $errors);
            }

            $sku      = (string) $group['sku'];
            $groupRows = $group['rows'];
            $lookup    = $this->quantityCheck->lookupSkuAcrossStores($sku);
            $processed++;

            $skuLabel = count($groupRows) > 1
                ? sprintf('%s (%d rows)', $sku, count($groupRows))
                : $sku;

            $groupUpdated   = 0;
            $groupUnchanged = 0;
            $groupMissing   = 0;
            $groupErrors    = 0;
            $sampleResult   = null;

            foreach ($groupRows as $row) {
                $result       = $this->quantityCheck->applyStoreLookupToRow($row, $lookup);
                $sampleResult ??= $result;

                if (($lookup['checked_ok'] ?? false) && ! ($lookup['found_any'] ?? false)) {
                    $groupMissing++;
                    $missing++;
                } elseif (! ($lookup['checked_ok'] ?? false) || (
                    ! ($result['ok'] ?? false) && ($lookup['found_any'] ?? false)
                )) {
                    // API failure, or DB save failure after a successful lookup.
                    $groupErrors++;
                    if ($groupErrors === 1) {
                        $errors[] = sprintf(
                            'SKU %s: %s',
                            $sku,
                            $result['message'] ?? 'Net32 check failed.',
                        );
                    }
                } elseif ((int) ($result['quantity'] ?? 0) !== (int) ($result['previous_quantity'] ?? $row['quantity'] ?? 0)) {
                    $groupUpdated++;
                    $updated++;
                } else {
                    $groupUnchanged++;
                    $unchanged++;
                }
            }

            if ($groupMissing > 0 && $groupUpdated === 0 && $groupUnchanged === 0 && $groupErrors === 0) {
                $this->progressLine(sprintf(
                    '[%d/%d] %s — not found in Net32',
                    $processed,
                    $total,
                    $skuLabel,
                ), 'yellow');
            } elseif ($groupErrors > 0 && $groupUpdated === 0 && $groupUnchanged === 0 && $groupMissing === 0) {
                $this->progressLine(sprintf(
                    '[%d/%d] %s — error: %s',
                    $processed,
                    $total,
                    $skuLabel,
                    $sampleResult['message'] ?? 'Net32 check failed.',
                ), 'red');
            } elseif ($groupUpdated > 0) {
                $this->progressLine(sprintf(
                    '[%d/%d] %s — updated %d row(s)%s',
                    $processed,
                    $total,
                    $skuLabel,
                    $groupUpdated,
                    $sampleResult !== null
                        ? sprintf(
                            ' (%s → %s)',
                            number_format((int) ($sampleResult['previous_quantity'] ?? 0)),
                            number_format((int) ($sampleResult['quantity'] ?? 0)),
                        )
                        : '',
                ), 'green');
            } else {
                $this->progressLine(sprintf(
                    '[%d/%d] %s — %s',
                    $processed,
                    $total,
                    $skuLabel,
                    $sampleResult['message'] ?? 'quantity confirmed',
                ), 'light_gray');
            }

            $this->reportProgress(
                $jobId,
                $logId,
                $pipelineImportJobId,
                $processed,
                $total,
                $scope,
                sprintf(
                    'Checked %d of %d unique SKUs (%s) on %s — rows updated %d, unchanged %d, not in Net32 %d.',
                    $processed,
                    $total,
                    $scopeLabel,
                    $storeLabel !== '' ? $storeLabel : 'Net32',
                    $updated,
                    $unchanged,
                    $missing,
                ),
                [
                    'processed' => $processed,
                    'updated'   => $updated,
                    'unchanged' => $unchanged,
                    'missing'   => $missing,
                    'errors'    => $errors,
                    'row_total' => $rowTotal,
                ],
            );

            if ($delaySeconds > 0 && $processed < $total) {
                usleep((int) round($delaySeconds * 1_000_000));
            }
        }

        return [
            'sheet_name' => $scope,
            'total'      => $total,
            'processed'  => $processed,
            'updated'    => $updated,
            'unchanged'  => $unchanged,
            'missing'    => $missing,
            'errors'     => $errors,
            'row_total'  => $rowTotal,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{sku: string, rows: list<array<string, mixed>>}>
     */
    private function groupRowsBySku(array $rows): array
    {
        /** @var array<string, array{sku: string, rows: list<array<string, mixed>>}> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            $sku   = trim((string) ($row['sku'] ?? ''));

            if ($rowId <= 0 || $sku === '') {
                continue;
            }

            $key = strtolower($sku);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'sku'  => $sku,
                    'rows' => [],
                ];
            }

            $groups[$key]['rows'][] = $row;
        }

        return array_values($groups);
    }

    /**
     * @param list<string> $errors
     *
     * @return array{
     *     sheet_name: string,
     *     total: int,
     *     processed: int,
     *     updated: int,
     *     unchanged: int,
     *     missing: int,
     *     errors: list<string>,
     *     cancelled: true
     * }
     */
    private function cancelledResult(
        string $scope,
        int $total,
        int $processed,
        int $updated,
        int $unchanged,
        int $missing,
        array $errors,
    ): array {
        return [
            'sheet_name' => $scope,
            'total'      => $total,
            'processed'  => $processed,
            'updated'    => $updated,
            'unchanged'  => $unchanged,
            'missing'    => $missing,
            'errors'     => $errors,
            'cancelled'  => true,
        ];
    }

    /**
     * @param array<string, mixed> $counts
     */
    private function reportProgress(
        ?int $jobId,
        ?int $logId,
        ?int $pipelineImportJobId,
        int $processed,
        int $total,
        string $scope,
        string $message,
        array $counts = [],
    ): void {
        if ($pipelineImportJobId !== null) {
            $extra = $counts === []
                ? []
                : [
                    'net32_processed' => $counts['processed'] ?? $processed,
                    'net32_updated'   => $counts['updated'] ?? 0,
                    'net32_unchanged' => $counts['unchanged'] ?? 0,
                    'net32_missing'   => $counts['missing'] ?? 0,
                ];

            service('inventoryImportJob')->updatePipelineProgress(
                $pipelineImportJobId,
                'net32',
                $processed,
                $total,
                $message,
                $extra,
            );

            return;
        }

        if ($jobId === null) {
            return;
        }

        service('inventoryQtySyncJob')->updateProgress(
            $jobId,
            $processed,
            $total,
            $scope,
            $message,
            $logId,
            $counts,
        );
    }

    private function isSyncCancelled(?int $jobId, ?int $pipelineImportJobId): bool
    {
        if ($pipelineImportJobId !== null && service('inventoryImportJob')->isCancelRequested($pipelineImportJobId)) {
            return true;
        }

        return $jobId !== null && service('inventoryQtySyncJob')->isCancelRequested($jobId);
    }

    private function formatScopeLabel(string $scope): string
    {
        return $scope === self::ALL_SHEETS ? 'All sheets' : 'Sheet "' . $scope . '"';
    }

    private function progressLine(string $message, string $color = 'white'): void
    {
        if (! $this->showProgress) {
            return;
        }

        CLI::write($message, $color);
    }
}
