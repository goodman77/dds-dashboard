<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class InventoryCancelQtySync extends BaseCommand
{
    protected $group       = 'Inventory';
    protected $name        = 'inventory:cancel-qty-sync';
    protected $description = 'Stop a queued or running Net32 quantity sync job.';
    protected $usage       = 'inventory:cancel-qty-sync [--job-id 1]';
    protected $options     = [
        '--job-id' => 'Quantity sync job ID to cancel. Defaults to the active job.',
    ];

    public function run(array $params)
    {
        $jobId = $this->resolveJobIdOption();

        $result = service('inventoryQtySyncJob')->requestCancel($jobId);

        if (! $result['ok']) {
            CLI::error($result['message']);

            return;
        }

        $color = ($result['status'] ?? '') === 'cancelled' ? 'yellow' : 'cyan';
        CLI::write($result['message'], $color);

        if (($result['job_id'] ?? null) !== null) {
            CLI::write(sprintf('Job #%d', (int) $result['job_id']), 'light_gray');
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
}
