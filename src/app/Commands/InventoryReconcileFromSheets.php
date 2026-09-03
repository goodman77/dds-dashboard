<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\InventoryQuantitySyncService;
use App\Services\InventoryShipStationCheckService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class InventoryReconcileFromSheets extends BaseCommand
{
    protected $group       = 'Inventory';
    protected $name        = 'inventory:reconcile-from-sheets';
    protected $description = 'Reconcile inventory with Google Sheets (add, update, remove SKUs). Use --full to also sync Net32 qty and check ShipStation.';
    protected $usage       = 'inventory:reconcile-from-sheets (--sheet "M6" | --all) [options]';
    protected $options     = [
        '--sheet'             => 'Reconcile only this worksheet tab name.',
        '--all'               => 'Reconcile every configured sheet tab.',
        '--full'              => 'Also run Net32 quantity sync and ShipStation location check after reconcile.',
        '--with-net32'        => 'After reconcile, sync quantities from Net32 for this scope.',
        '--with-shipstation'  => 'After reconcile, check ShipStation locations for this scope.',
        '--delay'             => 'Seconds to wait between Net32 API calls when adding SKUs or syncing qty (default: 3.5).',
        '--shipstation-delay' => 'Seconds to wait between ShipStation API calls (default: from ShipStation config).',
        '--dry-run'           => 'Preview sheet reconcile only; never runs Net32 or ShipStation phases.',
        '--skip-net32'        => 'With --full, skip the Net32 quantity sync phase.',
        '--skip-shipstation'  => 'With --full, skip the ShipStation location check phase.',
        '--quiet'             => 'Hide per-row progress output.',
    ];

    public function run(array $params)
    {
        $allSheets = CLI::getOption('all') !== null;
        $sheet     = CLI::getOption('sheet');
        $hasSheet  = is_string($sheet) && trim($sheet) !== '';

        if ($allSheets && $hasSheet) {
            CLI::error('Use either --sheet or --all, not both.');

            return;
        }

        if (! $allSheets && ! $hasSheet) {
            CLI::error('Choose one sheet with --sheet M6 or use --all for every sheet tab.');

            return;
        }

        $delay              = CLI::getOption('delay');
        $shipStationDelay   = CLI::getOption('shipstation-delay');
        $dryRun             = CLI::getOption('dry-run') !== null;
        $full               = CLI::getOption('full') !== null;
        $withNet32          = $full || CLI::getOption('with-net32') !== null;
        $withShipStation    = $full || CLI::getOption('with-shipstation') !== null;
        $skipNet32          = CLI::getOption('skip-net32') !== null;
        $skipShipStation    = CLI::getOption('skip-shipstation') !== null;
        $verbose            = CLI::getOption('quiet') === null;
        $scope              = $allSheets ? null : trim((string) $sheet);
        $runNet32           = $withNet32 && ! $skipNet32 && ! $dryRun;
        $runShipStation     = $withShipStation && ! $skipShipStation && ! $dryRun;

        if ($dryRun) {
            CLI::write('Dry run — sheet reconcile preview only. Net32 and ShipStation phases will be skipped.', 'yellow');
        } elseif (! $runNet32 && ! $runShipStation) {
            CLI::write(
                'Sheets-only mode. Net32 is called only when adding new SKUs. Use --full to also sync all quantities and check ShipStation.',
                'light_gray',
            );
        }

        CLI::newLine();

        $result = service('inventoryFullSync')->syncFromGoogleSheets(
            $scope,
            $delay !== null ? max(0.0, (float) $delay) : null,
            $shipStationDelay !== null ? max(0.0, (float) $shipStationDelay) : null,
            $dryRun,
            $verbose,
            $runNet32,
            $runShipStation,
        );

        $reconcile = $result['reconcile'];

        CLI::newLine();
        CLI::write('Sheet reconcile complete.', 'cyan');
        $this->printReconcileSummary($reconcile);

        if (isset($result['net32'])) {
            CLI::newLine();
            CLI::write('Net32 quantity sync complete.', 'cyan');
            $this->printNet32Summary($result['net32']);
        }

        if (isset($result['shipstation'])) {
            CLI::newLine();
            CLI::write('ShipStation location check complete.', 'cyan');
            $this->printShipStationSummary($result['shipstation']);
        }

        if ($runNet32 || $runShipStation) {
            CLI::newLine();
            CLI::write('View all phases on the Logs page.', 'light_gray');
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function printReconcileSummary(array $result): void
    {
        CLI::write(sprintf(
            'Sheets: %d | SKUs scanned: %d | Added: %d | Updated: %d | Removed: %d | Unchanged: %d | Not in Net32: %d',
            $result['sheets'],
            $result['scanned'],
            $result['added'],
            $result['updated'],
            $result['removed'],
            $result['unchanged'],
            $result['ignored'],
        ), $result['errors'] === [] ? 'green' : 'yellow');

        foreach ($result['errors'] as $error) {
            CLI::write($error, 'red');
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function printNet32Summary(array $result): void
    {
        $scopeLabel = ($result['sheet_name'] ?? '') === InventoryQuantitySyncService::ALL_SHEETS
            ? 'All sheets'
            : 'Sheet "' . ($result['sheet_name'] ?? '') . '"';

        CLI::write(sprintf(
            '%s | Checked: %d | Updated: %d | Unchanged: %d | Not in Net32: %d',
            $scopeLabel,
            $result['processed'],
            $result['updated'],
            $result['unchanged'],
            $result['missing'],
        ), ($result['errors'] ?? []) === [] ? 'green' : 'yellow');

        foreach ($result['errors'] ?? [] as $error) {
            CLI::write($error, 'red');
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function printShipStationSummary(array $result): void
    {
        $scopeLabel = ($result['sheet_name'] ?? '') === InventoryShipStationCheckService::ALL_SHEETS
            ? 'All sheets'
            : 'Sheet "' . ($result['sheet_name'] ?? '') . '"';

        CLI::write(sprintf(
            '%s | Checked: %d | Matched: %d | Mismatched: %d | Missing: %d | Empty location: %d',
            $scopeLabel,
            $result['processed'],
            $result['matched'],
            $result['mismatched'],
            $result['missing'],
            $result['empty_location'],
        ), ($result['errors'] ?? []) === [] ? 'green' : 'yellow');

        foreach ($result['errors'] ?? [] as $error) {
            CLI::write($error, 'red');
        }
    }
}
