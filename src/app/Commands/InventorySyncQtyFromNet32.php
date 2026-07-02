<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\InventoryQuantitySyncService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class InventorySyncQtyFromNet32 extends BaseCommand
{
    protected $group       = 'Inventory';
    protected $name        = 'inventory:sync-qty-from-net32';
    protected $description = 'Check SKUs against Net32 and update local quantities for one sheet or all inventory.';
    protected $usage       = 'inventory:sync-qty-from-net32 (--sheet "M6" | --all) [options]';
    protected $options     = [
        '--sheet'  => 'Worksheet tab name to check.',
        '--all'    => 'Check every SKU in inventory across all sheet tabs.',
        '--job-id' => 'Run a queued background quantity sync job by ID.',
        '--delay'  => 'Seconds to wait between Net32 API calls (default: from Net32 config).',
        '--quiet'  => 'Hide per-SKU progress output.',
    ];

    public function run(array $params)
    {
        $jobId = $this->resolveJobIdOption();

        if ($jobId !== null) {
            service('inventoryQtySyncJob')->run($jobId);

            return;
        }

        $allSheets = CLI::getOption('all') !== null;
        $sheet     = CLI::getOption('sheet');
        $hasSheet  = is_string($sheet) && trim($sheet) !== '';

        if ($allSheets && $hasSheet) {
            CLI::error('Use either --sheet or --all, not both.');

            return;
        }

        if (! $allSheets && ! $hasSheet) {
            CLI::error('Choose one sheet with --sheet M6 or use --all for every SKU in inventory.');

            return;
        }

        $delay   = CLI::getOption('delay');
        $verbose = CLI::getOption('quiet') === null;

        CLI::newLine();

        try {
            $result = $allSheets
                ? service('inventoryQtySyncJob')->runForAll(
                    $delay !== null ? max(0.0, (float) $delay) : null,
                    $verbose,
                )
                : service('inventoryQtySyncJob')->runForSheet(
                    trim((string) $sheet),
                    $delay !== null ? max(0.0, (float) $delay) : null,
                    $verbose,
                );
        } catch (\Throwable $exception) {
            CLI::error($exception->getMessage());

            return;
        }

        CLI::newLine();
        CLI::write('Net32 quantity sync complete.', 'cyan');

        $scopeLabel = ($result['sheet_name'] ?? '') === InventoryQuantitySyncService::ALL_SHEETS
            ? 'All sheets'
            : (string) ($result['sheet_name'] ?? '');

        CLI::write(sprintf(
            'Job #%d | Scope: %s | Checked: %d | Updated: %d | Unchanged: %d | Not in Net32: %d',
            $result['job_id'],
            $scopeLabel,
            $result['processed'],
            $result['updated'],
            $result['unchanged'],
            $result['missing'],
        ), ($result['errors'] ?? []) === [] ? 'green' : 'yellow');

        foreach ($result['errors'] as $error) {
            CLI::write($error, 'red');
        }

        CLI::newLine();
        CLI::write('View progress and status on the Logs page (action: Net32 Qty Sync).', 'light_gray');
    }

    private function resolveJobIdOption(): ?int
    {
        $jobId = CLI::getOption('job-id');

        if (is_string($jobId) && $jobId !== '') {
            return (int) $jobId;
        }

        foreach (CLI::getOptions() as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (str_starts_with($key, 'job-id=')) {
                $id = substr($key, strlen('job-id='));

                return $id !== '' ? (int) $id : null;
            }

            if ($key === 'job-id' && is_string($value) && $value !== '') {
                return (int) $value;
            }
        }

        return null;
    }
}
