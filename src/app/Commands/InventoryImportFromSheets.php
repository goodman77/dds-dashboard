<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class InventoryImportFromSheets extends BaseCommand
{
    protected $group       = 'Inventory';
    protected $name        = 'inventory:import-from-sheets';
    protected $description = 'Import inventory rows from Google Sheets for SKUs that exist in Net32.';
    protected $usage       = 'inventory:import-from-sheets [options]';
    protected $options     = [
        '--job-id'  => 'Run a queued background import job by ID.',
        '--sheet'   => 'Import only this worksheet tab name.',
        '--delay'   => 'Seconds to wait between Net32 API calls (default: 3.5).',
        '--dry-run' => 'Scan sheets and Net32 without writing to the database.',
        '--quiet'   => 'Hide per-row progress output.',
    ];

    public function run(array $params)
    {
        $jobId = $this->resolveJobIdOption();

        if ($jobId !== null) {
            service('inventoryImportJob')->run($jobId);

            return;
        }

        $sheet   = CLI::getOption('sheet');
        $delay   = CLI::getOption('delay');
        $dryRun  = CLI::getOption('dry-run') !== null;
        $verbose = CLI::getOption('quiet') === null;

        if ($dryRun) {
            CLI::write('Dry run — no database changes will be made.', 'yellow');
        }

        CLI::newLine();

        $result = service('inventoryImport')->importFromGoogleSheets(
            is_string($sheet) && $sheet !== '' ? $sheet : null,
            $delay !== null ? max(0.0, (float) $delay) : null,
            $dryRun,
            $verbose,
        );

        CLI::newLine();
        CLI::write('Import complete.', 'cyan');

        CLI::write(sprintf(
            'Sheets: %d | SKUs scanned: %d | Imported: %d | Already in DB: %d | Not in Net32: %d',
            $result['sheets'],
            $result['scanned'],
            $result['imported'],
            $result['skipped'],
            $result['ignored'],
        ), $result['errors'] === [] ? 'green' : 'yellow');

        foreach ($result['errors'] as $error) {
            CLI::write($error, 'red');
        }
    }

    private function resolveJobIdOption(): ?int
    {
        $jobId = CLI::getOption('job-id');

        if (is_string($jobId) && $jobId !== '') {
            return (int) $jobId;
        }

        // Spark passes "--job-id=1" as a single token, which CI stores as "job-id=1".
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
