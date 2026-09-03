<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\InventoryShipStationCheckService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class InventoryCheckShipStationLocations extends BaseCommand
{
    protected $group       = 'Inventory';
    protected $name        = 'inventory:check-shipstation-locations';
    protected $description = 'Sync SKUs with ShipStation (move to correct bin) for one sheet or all inventory.';
    protected $usage       = 'inventory:check-shipstation-locations (--sheet "M6" | --all) [options]';
    protected $options     = [
        '--sheet'  => 'Worksheet tab name to check.',
        '--all'    => 'Check every SKU in inventory across all sheet tabs.',
        '--job-id' => 'Run a queued background ShipStation location check job by ID.',
        '--delay'  => 'Seconds to wait between ShipStation API calls (default: from ShipStation config).',
        '--quiet'  => 'Hide per-SKU progress output.',
    ];

    public function run(array $params)
    {
        $jobId = $this->resolveJobIdOption();

        if ($jobId !== null) {
            service('inventoryShipStationCheckJob')->run($jobId);

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
                ? service('inventoryShipStationCheck')->checkAll(
                    $delay !== null ? max(0.0, (float) $delay) : null,
                    $verbose,
                )
                : service('inventoryShipStationCheck')->checkSheet(
                    trim((string) $sheet),
                    $delay !== null ? max(0.0, (float) $delay) : null,
                    $verbose,
                );

            $this->printSummary($result);
        } catch (\Throwable $exception) {
            CLI::error($exception->getMessage());
        }
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

    /**
     * @param array<string, mixed> $result
     */
    private function printSummary(array $result): void
    {
        $scopeLabel = ($result['sheet_name'] ?? '') === InventoryShipStationCheckService::ALL_SHEETS
            ? 'All sheets'
            : 'Sheet "' . ($result['sheet_name'] ?? '') . '"';

        CLI::newLine();
        CLI::write($scopeLabel . ' ShipStation location sync finished.', 'green');
        CLI::write(sprintf(
            'Processed: %d | Moved: %d | Match: %d | Mismatch: %d | Missing: %d | Empty location: %d',
            (int) ($result['processed'] ?? 0),
            (int) ($result['synced'] ?? 0),
            (int) ($result['matched'] ?? 0),
            (int) ($result['mismatched'] ?? 0),
            (int) ($result['missing'] ?? 0),
            (int) ($result['empty_location'] ?? 0),
        ), 'white');

        foreach ($result['errors'] ?? [] as $error) {
            CLI::write('  - ' . $error, 'red');
        }
    }
}
