<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryModel;
use CodeIgniter\CLI\CLI;

class InventoryShipStationCheckService
{
    public const ALL_SHEETS = '*';

    private bool $showProgress = false;

    public function __construct(
        private readonly InventoryModel $inventory,
        private readonly ShipStationLocationSyncService $locationSync,
    ) {
    }

    /**
     * Sync every SKU on a sheet — same {@see ShipStationLocationSyncService::syncRow()} as the row pin button.
     *
     * @return array{
     *     sheet_name: string,
     *     total: int,
     *     processed: int,
     *     synced: int,
     *     matched: int,
     *     mismatched: int,
     *     missing: int,
     *     empty_location: int,
     *     errors: list<string>
     * }
     */
    public function checkSheet(
        string $sheetName,
        ?float $delaySeconds = null,
        bool $verbose = true,
        ?int $jobId = null,
        ?int $pipelineImportJobId = null,
    ): array {
        return $this->runSheetScope(
            $this->inventory->findBySheetName(trim($sheetName)),
            trim($sheetName),
            $delaySeconds,
            $verbose,
            $jobId,
            $pipelineImportJobId,
        );
    }

    /**
     * Sync every SKU in inventory — same {@see ShipStationLocationSyncService::syncRow()} as the row pin button.
     *
     * @return array{
     *     sheet_name: string,
     *     total: int,
     *     processed: int,
     *     synced: int,
     *     matched: int,
     *     mismatched: int,
     *     missing: int,
     *     empty_location: int,
     *     errors: list<string>
     * }
     */
    public function checkAll(
        ?float $delaySeconds = null,
        bool $verbose = true,
        ?int $jobId = null,
        ?int $pipelineImportJobId = null,
    ): array {
        return $this->runSheetScope(
            $this->inventory->findAllForQuantitySync(),
            self::ALL_SHEETS,
            $delaySeconds,
            $verbose,
            $jobId,
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
     *     synced: int,
     *     matched: int,
     *     mismatched: int,
     *     missing: int,
     *     empty_location: int,
     *     errors: list<string>
     * }
     */
    private function runSheetScope(
        array $rows,
        string $scope,
        ?float $delaySeconds = null,
        bool $verbose = true,
        ?int $jobId = null,
        ?int $pipelineImportJobId = null,
    ): array {
        $this->showProgress = $verbose && is_cli();
        $scopeLabel         = $this->formatScopeLabel($scope);
        $total              = count($rows);

        if ($this->showProgress) {
            $delaySeconds ??= (float) config('ShipStation')->requestDelaySeconds;
            CLI::write(sprintf(
                '%s: syncing %d SKU row(s) with ShipStation (%s delay)...',
                $scopeLabel,
                $total,
                $this->formatDelayLabel($delaySeconds),
            ), 'cyan');
        }

        if ($pipelineImportJobId !== null) {
            service('inventoryImportJob')->updatePipelineProgress(
                $pipelineImportJobId,
                'shipstation',
                0,
                $total,
                sprintf('Syncing %d SKU(s) (%s)...', $total, $scopeLabel),
            );
        }

        return $this->locationSync->syncManyRows(
            $rows,
            $scope,
            $delaySeconds,
            $pipelineImportJobId !== null
                ? function (int $processed, int $total, array $totals) use ($pipelineImportJobId, $scopeLabel): void {
                    service('inventoryImportJob')->updatePipelineProgress(
                        $pipelineImportJobId,
                        'shipstation',
                        $processed,
                        $total,
                        sprintf(
                            'Synced %d of %d (%s) — moved %d, match %d, mismatch %d, missing %d, empty %d.',
                            $processed,
                            $total,
                            $scopeLabel,
                            (int) ($totals['synced'] ?? 0),
                            (int) ($totals['matched'] ?? 0),
                            (int) ($totals['mismatched'] ?? 0),
                            (int) ($totals['missing'] ?? 0),
                            (int) ($totals['empty_location'] ?? 0),
                        ),
                        [
                            'shipstation_processed' => $processed,
                            'shipstation_synced'    => (int) ($totals['synced'] ?? 0),
                        ],
                    );
                }
                : ($jobId !== null
                    ? function (int $processed, int $total, array $totals) use ($jobId, $scope, $scopeLabel): void {
                        service('inventoryShipStationCheckJob')->updateProgress(
                            $jobId,
                            $processed,
                            $total,
                            $scope,
                            sprintf(
                                'Synced %d of %d (%s) — moved %d, match %d, mismatch %d, missing %d, empty %d.',
                                $processed,
                                $total,
                                $scopeLabel,
                                (int) ($totals['synced'] ?? 0),
                                (int) ($totals['matched'] ?? 0),
                                (int) ($totals['mismatched'] ?? 0),
                                (int) ($totals['missing'] ?? 0),
                                (int) ($totals['empty_location'] ?? 0),
                            ),
                            $totals,
                        );
                    }
                    : null),
            $pipelineImportJobId !== null
                ? static fn (): bool => service('inventoryImportJob')->isCancelRequested($pipelineImportJobId)
                : ($jobId !== null
                    ? static fn (): bool => service('inventoryShipStationCheckJob')->isCancelRequested($jobId)
                    : null),
            $this->showProgress
                ? function (string $message, string $color): void {
                    CLI::write($message, $color);
                }
                : null,
        );
    }

    private function formatScopeLabel(string $scope): string
    {
        return $scope === self::ALL_SHEETS ? 'All sheets' : 'Sheet "' . $scope . '"';
    }

    private function formatDelayLabel(float $delaySeconds): string
    {
        return rtrim(rtrim(number_format($delaySeconds, 1, '.', ''), '0'), '.') . 's';
    }
}
