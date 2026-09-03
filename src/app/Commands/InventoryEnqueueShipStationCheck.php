<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class InventoryEnqueueShipStationCheck extends BaseCommand
{
    protected $group       = 'Inventory';
    protected $name        = 'inventory:enqueue-shipstation-check';
    protected $description = 'Queue a ShipStation location check job for cron (shared hosting without SSH).';
    protected $usage       = 'inventory:enqueue-shipstation-check (--sheet "M6" | --all) [options]';
    protected $options     = [
        '--sheet' => 'Worksheet tab name to check.',
        '--all'   => 'Queue a check for every SKU in inventory.',
        '--quiet' => 'Only print output when a job is queued or skipped.',
    ];

    public function run(array $params)
    {
        $allSheets = CLI::getOption('all') !== null;
        $sheet     = CLI::getOption('sheet');
        $hasSheet  = is_string($sheet) && trim($sheet) !== '';
        $verbose   = CLI::getOption('quiet') === null;

        if ($allSheets && $hasSheet) {
            CLI::error('Use either --sheet or --all, not both.');

            return;
        }

        if (! $allSheets && ! $hasSheet) {
            CLI::error('Choose one sheet with --sheet M6 or use --all for every SKU in inventory.');

            return;
        }

        try {
            $result = $allSheets
                ? service('inventoryShipStationCheckJob')->enqueueForAll()
                : service('inventoryShipStationCheckJob')->enqueueForSheet(trim((string) $sheet));
        } catch (\Throwable $exception) {
            CLI::error($exception->getMessage());

            return;
        }

        if (! $verbose && ! $result['queued']) {
            return;
        }

        $color = $result['queued']
            ? (($result['spawned'] ?? false) ? 'green' : 'cyan')
            : 'yellow';

        CLI::write($result['message'], $color);
    }
}
