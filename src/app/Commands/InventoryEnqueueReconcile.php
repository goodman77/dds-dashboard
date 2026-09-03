<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\InventoryImportJobService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class InventoryEnqueueReconcile extends BaseCommand
{
    protected $group       = 'Inventory';
    protected $name        = 'inventory:enqueue-reconcile';
    protected $description = 'Queue a Google Sheets reconcile, then Net32 quantity sync (for cron).';
    protected $usage       = 'inventory:enqueue-reconcile (--sheet "M6" | --all) [options]';
    protected $options     = [
        '--sheet'      => 'Worksheet tab name to reconcile.',
        '--all'        => 'Reconcile every sheet tab, then sync Net32 quantities.',
        '--skip-net32' => 'Only reconcile Google Sheets; do not sync Net32 quantities.',
        '--quiet'      => 'Only print output when a job is queued or skipped.',
    ];

    public function run(array $params)
    {
        $allSheets = CLI::getOption('all') !== null;
        $sheet     = CLI::getOption('sheet');
        $hasSheet  = is_string($sheet) && trim($sheet) !== '';
        $withNet32 = CLI::getOption('skip-net32') === null;
        $verbose   = CLI::getOption('quiet') === null;

        if ($allSheets && $hasSheet) {
            CLI::error('Use either --sheet or --all, not both.');

            return;
        }

        if (! $allSheets && ! $hasSheet) {
            CLI::error('Choose one sheet with --sheet M6 or use --all for every sheet tab.');

            return;
        }

        try {
            $result = service('inventoryImportJob')->dispatch(
                $allSheets ? null : trim((string) $sheet),
                null,
                InventoryImportJobService::MODE_RECONCILE,
                $withNet32,
                false,
            );
        } catch (\Throwable $exception) {
            CLI::error($exception->getMessage());

            return;
        }

        if (! $verbose && ! ($result['started'] ?? false)) {
            return;
        }

        $color = ($result['started'] ?? false) ? 'green' : 'yellow';

        CLI::write((string) ($result['message'] ?? 'Could not queue reconcile.'), $color);

        if ($verbose && ($result['job_id'] ?? null) !== null) {
            CLI::write(
                sprintf(
                    'Job #%d | Scope: %s | Net32: %s',
                    (int) $result['job_id'],
                    $allSheets ? 'All sheets' : trim((string) $sheet),
                    $withNet32 ? 'yes' : 'no',
                ),
                'light_gray',
            );
        }
    }
}
