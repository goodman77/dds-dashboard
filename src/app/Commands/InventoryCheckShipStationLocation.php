<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class InventoryCheckShipStationLocation extends BaseCommand
{
    protected $group       = 'Inventory';
    protected $name        = 'inventory:check-shipstation-location';
    protected $description = 'Check one inventory row against ShipStation and print the current location.';
    protected $usage       = 'inventory:check-shipstation-location (--id 123 | --sku ABC123)';
    protected $options     = [
        '--id'  => 'Inventory row ID to check.',
        '--sku' => 'SKU to look up directly in ShipStation (does not update the database).',
    ];

    public function run(array $params)
    {
        $id  = CLI::getOption('id');
        $sku = CLI::getOption('sku');

        $hasId  = is_string($id) && trim($id) !== '';
        $hasSku = is_string($sku) && trim($sku) !== '';

        if ($hasId && $hasSku) {
            CLI::error('Use either --id or --sku, not both.');

            return;
        }

        if (! $hasId && ! $hasSku) {
            CLI::error('Provide --id for an inventory row or --sku for a direct ShipStation lookup.');

            return;
        }

        if ($hasSku) {
            $this->lookupSku(trim((string) $sku));

            return;
        }

        $result = service('shipStationLocationCheck')->checkRow((int) $id);

        if ($result['ok']) {
            CLI::write($result['message'], 'green');
        } else {
            CLI::error($result['message']);
        }
    }

    private function lookupSku(string $sku): void
    {
        try {
            $result = service('shipStation')->inventory()->findPrimaryLocationForSku($sku);
        } catch (\Throwable $exception) {
            CLI::error($exception->getMessage());

            return;
        }

        if ($result === null) {
            CLI::error(sprintf('SKU %s was not found in ShipStation inventory.', $sku));

            return;
        }

        CLI::write(sprintf('SKU: %s', $result['sku']), 'yellow');
        CLI::write('Location: ' . ($result['location_name'] ?? '—'));
        CLI::write('Warehouse: ' . ($result['warehouse_name'] ?? '—'));
        CLI::write('On hand: ' . $result['on_hand']);
        CLI::write('Available: ' . $result['available']);

        if (! ($result['in_configured_warehouse'] ?? true)) {
            CLI::write(
                'Note: SKU is in ShipStation but not in ' . service('shipStation')->inventory()->getConfiguredWarehouseLabel() . '.',
                'yellow',
            );
        }
    }
}
