<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\CLI\CLI;

class InventoryFullSyncService
{
    public function __construct(
        private readonly InventoryReconcileFromSheetsService $reconcile,
        private readonly InventoryQuantitySyncService $qtySync,
        private readonly InventoryShipStationCheckService $shipStationCheck,
    ) {
    }

    /**
     * @return array{
     *     sheet_scope: string|null,
     *     reconcile: array<string, mixed>,
     *     net32?: array<string, mixed>,
     *     shipstation?: array<string, mixed>
     * }
     */
    public function syncFromGoogleSheets(
        ?string $onlySheet = null,
        ?float $net32DelaySeconds = null,
        ?float $shipStationDelaySeconds = null,
        bool $dryRun = false,
        bool $verbose = true,
        bool $runNet32 = false,
        bool $runShipStation = false,
    ): array {
        $showProgress = $verbose && is_cli();
        $onlySheet    = $onlySheet !== null && $onlySheet !== '' ? trim($onlySheet) : null;

        if ($showProgress) {
            CLI::newLine();
            CLI::write('=== Phase 1: Google Sheets reconcile ===', 'cyan');
            CLI::newLine();
        }

        $reconcileResult = $this->reconcile->reconcileFromGoogleSheets(
            $onlySheet,
            $net32DelaySeconds,
            $dryRun,
            $verbose,
        );

        $result = [
            'sheet_scope' => $onlySheet,
            'reconcile'   => $reconcileResult,
        ];

        if ($dryRun || (! $runNet32 && ! $runShipStation)) {
            return $result;
        }

        $scope = $onlySheet ?? InventoryQuantitySyncService::ALL_SHEETS;

        if ($runNet32) {
            if ($showProgress) {
                CLI::newLine();
                CLI::write('=== Phase 2: Net32 quantity sync ===', 'cyan');
                CLI::newLine();
            }

            $net32Result = $scope === InventoryQuantitySyncService::ALL_SHEETS
                ? $this->qtySync->syncAllFromNet32($net32DelaySeconds, $verbose)
                : $this->qtySync->syncSheetFromNet32($scope, $net32DelaySeconds, $verbose);

            service('activityLog')->logInventoryQtySyncResult($net32Result);
            $result['net32'] = $net32Result;
        }

        if ($runShipStation) {
            if ($showProgress) {
                CLI::newLine();
                CLI::write('=== Phase 3: ShipStation location check ===', 'cyan');
                CLI::newLine();
            }

            $shipStationResult = $scope === InventoryShipStationCheckService::ALL_SHEETS
                ? $this->shipStationCheck->checkAll($shipStationDelaySeconds, $verbose)
                : $this->shipStationCheck->checkSheet($scope, $shipStationDelaySeconds, $verbose);

            service('activityLog')->logInventoryShipStationCheckResult($shipStationResult);
            $result['shipstation'] = $shipStationResult;
        }

        return $result;
    }
}
