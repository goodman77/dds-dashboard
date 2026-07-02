<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ProcessInventoryQtySyncQueue extends BaseCommand
{
    protected $group       = 'Inventory';
    protected $name        = 'inventory:process-qty-sync-queue';
    protected $description = 'Process the next queued Net32 quantity sync job (for cron on shared hosting).';
    protected $usage       = 'inventory:process-qty-sync-queue [options]';
    protected $options     = [
        '--quiet' => 'Only print output when a job runs or fails.',
    ];

    public function run(array $params)
    {
        $result  = service('inventoryQtySyncJob')->processQueue();
        $verbose = CLI::getOption('quiet') === null;

        if (! $verbose && in_array($result['action'], ['idle', 'busy'], true)) {
            return;
        }

        $color = match ($result['action']) {
            'completed' => 'green',
            'started'   => 'green',
            'failed'    => 'red',
            'busy'      => 'yellow',
            default     => 'white',
        };

        CLI::write($result['message'], $color);
    }
}
