<?php

declare(strict_types=1);

namespace App\Services;

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
     *     errors: list<string>
     * }
     */
    public function syncSheetFromNet32(
        string $sheetName,
        ?float $delaySeconds = null,
        bool $verbose = true,
        ?int $jobId = null,
        ?int $logId = null,
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
     *     errors: list<string>
     * }
     */
    public function syncAllFromNet32(
        ?float $delaySeconds = null,
        bool $verbose = true,
        ?int $jobId = null,
        ?int $logId = null,
    ): array {
        $rows = $this->inventory->findAllForQuantitySync();

        return $this->syncRowsFromNet32(
            $rows,
            self::ALL_SHEETS,
            $delaySeconds,
            $verbose,
            $jobId,
            $logId,
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
     *     errors: list<string>
     * }
     */
    private function syncRowsFromNet32(
        array $rows,
        string $scope,
        ?float $delaySeconds = null,
        bool $verbose = true,
        ?int $jobId = null,
        ?int $logId = null,
    ): array {
        $delaySeconds ??= (float) config('Net32')->requestDelaySeconds;
        $this->showProgress = $verbose && is_cli();
        $scopeLabel = $this->formatScopeLabel($scope);
        $total     = count($rows);
        $processed = 0;
        $updated   = 0;
        $unchanged = 0;
        $missing   = 0;
        $errors    = [];

        if ($this->showProgress) {
            CLI::write(sprintf('%s: checking %d SKU row(s) against Net32...', $scopeLabel, $total), 'cyan');
        }

        if ($jobId !== null) {
            service('inventoryQtySyncJob')->updateProgress(
                $jobId,
                0,
                $total,
                $scope,
                sprintf('Checking %d SKU(s) (%s)...', $total, $scopeLabel),
                $logId,
            );
        }

        foreach ($rows as $row) {
            if ($jobId !== null && service('inventoryQtySyncJob')->isCancelRequested($jobId)) {
                return array_merge([
                    'sheet_name' => $scope,
                    'total'      => $total,
                    'processed'  => $processed,
                    'updated'    => $updated,
                    'unchanged'  => $unchanged,
                    'missing'    => $missing,
                    'errors'     => $errors,
                    'cancelled'  => true,
                ]);
            }

            $rowId = (int) ($row['id'] ?? 0);
            $sku   = trim((string) ($row['sku'] ?? ''));

            if ($rowId <= 0 || $sku === '') {
                continue;
            }

            $previousQuantity = (int) ($row['quantity'] ?? 0);
            $result           = $this->quantityCheck->checkRow($rowId);
            $processed++;

            if ($result['ok']) {
                $newQuantity = (int) ($result['quantity'] ?? $previousQuantity);
                $skuLabel    = $scope === self::ALL_SHEETS
                    ? sprintf('%s (%s)', $sku, (string) ($row['sheet_name'] ?? ''))
                    : $sku;

                if ($newQuantity !== $previousQuantity) {
                    $updated++;
                    $line = sprintf(
                        '[%d/%d] %s — quantity %s → %s',
                        $processed,
                        $total,
                        $skuLabel,
                        number_format($previousQuantity),
                        number_format($newQuantity),
                    );
                    $this->progressLine($line, 'green');
                } else {
                    $unchanged++;
                    $this->progressLine(sprintf(
                        '[%d/%d] %s — quantity confirmed at %s',
                        $processed,
                        $total,
                        $skuLabel,
                        number_format($newQuantity),
                    ), 'light_gray');
                }
            } elseif (($result['sku_net32_exists'] ?? null) === false) {
                $missing++;
                $skuLabel = $scope === self::ALL_SHEETS
                    ? sprintf('%s (%s)', $sku, (string) ($row['sheet_name'] ?? ''))
                    : $sku;
                $this->progressLine(sprintf(
                    '[%d/%d] %s — not found in Net32',
                    $processed,
                    $total,
                    $skuLabel,
                ), 'yellow');
            } else {
                $message = $result['message'] ?? 'Net32 check failed.';
                $errors[] = sprintf('SKU %s: %s', $sku, $message);
                $this->progressLine(sprintf(
                    '[%d/%d] %s — error: %s',
                    $processed,
                    $total,
                    $sku,
                    $message,
                ), 'red');
            }

            if ($jobId !== null) {
                service('inventoryQtySyncJob')->updateProgress(
                    $jobId,
                    $processed,
                    $total,
                    $scope,
                    sprintf(
                        'Checked %d of %d SKU(s) (%s) — updated %d, unchanged %d, not in Net32 %d.',
                        $processed,
                        $total,
                        $scopeLabel,
                        $updated,
                        $unchanged,
                        $missing,
                    ),
                    $logId,
                    [
                        'processed' => $processed,
                        'updated'   => $updated,
                        'unchanged' => $unchanged,
                        'missing'   => $missing,
                        'errors'    => $errors,
                    ],
                );
            }

            $this->sleepBetweenRequests($delaySeconds);
        }

        return [
            'sheet_name' => $scope,
            'total'      => $total,
            'processed'  => $processed,
            'updated'    => $updated,
            'unchanged'  => $unchanged,
            'missing'    => $missing,
            'errors'     => $errors,
        ];
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

    private function sleepBetweenRequests(float $delaySeconds): void
    {
        if ($delaySeconds <= 0) {
            return;
        }

        usleep((int) round($delaySeconds * 1_000_000));
    }
}
