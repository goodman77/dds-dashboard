<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class InventorySyncShipStationLocation extends BaseCommand
{
    protected $group       = 'Inventory';
    protected $name        = 'inventory:sync-shipstation-location';
    protected $description = 'Sync one inventory row location in ShipStation (move/consolidate to the sheet bin).';
    protected $usage       = 'inventory:sync-shipstation-location --id 123';
    protected $options     = [
        '--id' => 'Inventory row ID to sync.',
    ];

    public function run(array $params)
    {
        $id = CLI::getOption('id');

        if (! is_string($id) || trim($id) === '') {
            CLI::error('Provide --id for an inventory row to sync.');

            return;
        }

        $result = service('shipStationLocationSync')->syncRow((int) $id);

        if ($result['ok'] ?? false) {
            CLI::write($result['message'] ?? 'ShipStation sync finished.', 'green');
        } else {
            CLI::error($result['message'] ?? 'ShipStation sync failed.');
        }
    }
}
