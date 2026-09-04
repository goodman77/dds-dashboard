<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\ShipStation\Exceptions\ShipStationApiException;
use App\Libraries\ShipStation\Resources\InventoryResource;
use App\Models\InventoryModel;

class ShipStationLocationCheckService
{
    public function __construct(
        private readonly InventoryModel $inventory,
        private readonly InventoryResource $shipStationInventory,
    ) {
    }

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     shipstation_location?: string|null,
     *     shipstation_warehouse?: string|null,
     *     shipstation_on_hand?: int|null,
     *     expected_location?: string|null,
     *     location_matches?: bool|null,
     *     shipstation_exists?: bool|null
     * }
     */
    public function checkRow(int $id): array
    {
        $row = $this->inventory->find($id);

        if ($row === null) {
            return ['ok' => false, 'message' => 'Inventory row not found.'];
        }

        $sku = trim((string) ($row['sku'] ?? ''));

        if ($sku === '') {
            return ['ok' => false, 'message' => 'This row has no SKU to check.'];
        }

        $checkedAt        = date('Y-m-d H:i:s');
        $expectedLocation = $this->buildExpectedLocation($row);

        try {
            $result = $this->shipStationInventory->findBestLocationForSku($sku, $expectedLocation);
        } catch (ShipStationApiException $exception) {
            $this->inventory->update($id, [
                'shipstation_exists'     => 0,
                'shipstation_checked_at' => $checkedAt,
            ]);

            return [
                'ok'                 => false,
                'message'            => $exception->getMessage(),
                'expected_location'  => $expectedLocation,
                'shipstation_exists' => false,
            ];
        }

        if ($result === null) {
            $this->inventory->update($id, [
                'shipstation_location'          => null,
                'shipstation_warehouse'         => null,
                'shipstation_on_hand'           => null,
                'shipstation_exists'            => 0,
                'shipstation_location_matches'  => null,
                'shipstation_checked_at'        => $checkedAt,
            ]);

            return [
                'ok'                  => false,
                'message'             => sprintf('SKU %s was not found in ShipStation inventory.', $sku),
                'expected_location'   => $expectedLocation,
                'shipstation_exists'  => false,
            ];
        }

        $locationName  = $result['location_name'];
        $warehouseName = $result['warehouse_name'];
        $onHand        = $result['on_hand'];
        $inConfiguredWarehouse = (bool) ($result['in_configured_warehouse'] ?? true);
        $locationMatches = $inConfiguredWarehouse
            ? $this->locationsMatch($expectedLocation, $locationName)
            : false;

        if (! $this->inventory->update($id, [
            'shipstation_location'         => $locationName,
            'shipstation_warehouse'        => $warehouseName,
            'shipstation_on_hand'          => $onHand,
            'shipstation_exists'           => 1,
            'shipstation_location_matches' => $locationMatches === null ? null : ($locationMatches ? 1 : 0),
            'shipstation_checked_at'       => $checkedAt,
        ])) {
            return ['ok' => false, 'message' => 'Could not save the ShipStation location.'];
        }

        if (! $inConfiguredWarehouse) {
            $message = sprintf(
                'SKU %s is in ShipStation at %s (warehouse %s), but not in %s.',
                $sku,
                $locationName !== null && $locationName !== '' ? $locationName : 'an unknown location',
                $warehouseName !== null && $warehouseName !== '' ? $warehouseName : 'another warehouse',
                $this->shipStationInventory->getConfiguredWarehouseLabel(),
            );

            return [
                'ok'                      => true,
                'message'                 => $message,
                'shipstation_location'    => $locationName,
                'shipstation_warehouse'   => $warehouseName,
                'shipstation_on_hand'     => $onHand,
                'expected_location'       => $expectedLocation,
                'location_matches'        => false,
                'shipstation_exists'      => true,
                'in_configured_warehouse' => false,
            ];
        }

        $message = $locationName !== null && $locationName !== ''
            ? sprintf('ShipStation location: %s (on hand %d).', $locationName, $onHand)
            : sprintf('SKU found in ShipStation with on-hand %d, but no location assigned.', $onHand);

        if ($expectedLocation !== null && $locationName !== null && ! $locationMatches) {
            $message .= sprintf(' Expected %s.', $expectedLocation);
        }

        return [
            'ok'                    => true,
            'message'               => $message,
            'shipstation_location'  => $locationName,
            'shipstation_warehouse' => $warehouseName,
            'shipstation_on_hand'   => $onHand,
            'expected_location'     => $expectedLocation,
            'location_matches'      => $locationMatches,
            'shipstation_exists'    => true,
            'in_configured_warehouse' => true,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function buildExpectedLocation(array $row): ?string
    {
        $rack = trim((string) ($row['rack'] ?? ''));
        $bin  = trim((string) ($row['bin'] ?? ''));

        if ($rack === '' && $bin === '') {
            return null;
        }

        $sheetName    = strtoupper(trim((string) ($row['sheet_name'] ?? '')));
        $isWarehouse  = $sheetName === 'X' || str_contains(strtoupper($rack), 'X');
        $prefix       = $isWarehouse ? 'WH' : 'MR';
        $location     = rtrim(preg_replace('/\s+-\s*$/', '', "$prefix - $rack - $bin") ?? '', ' ');

        return $location !== '' ? $location : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function resolveInConfiguredWarehouseForRow(array $row): ?bool
    {
        if ((int) ($row['shipstation_exists'] ?? 0) !== 1) {
            return null;
        }

        $configuredWarehouseId = $this->shipStationInventory->getConfiguredWarehouseId();

        if ($configuredWarehouseId === '') {
            return true;
        }

        $storedWarehouse = trim((string) ($row['shipstation_warehouse'] ?? ''));

        if ($storedWarehouse === '') {
            return null;
        }

        $configuredWarehouseName = $this->shipStationInventory->getWarehouseName($configuredWarehouseId);

        if ($configuredWarehouseName === null || $configuredWarehouseName === '') {
            return null;
        }

        return strcasecmp($storedWarehouse, $configuredWarehouseName) === 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function resolveLocationMatchForRow(array $row): ?bool
    {
        if ((int) ($row['shipstation_exists'] ?? 0) !== 1) {
            return null;
        }

        $location = trim((string) ($row['shipstation_location'] ?? ''));

        if ($location === '') {
            return null;
        }

        return $this->locationsMatch($this->buildExpectedLocation($row), $location);
    }

    public function locationsMatch(?string $expected, ?string $actual): ?bool
    {
        if ($expected === null || $actual === null) {
            return null;
        }

        return $this->shipStationInventory->locationNamesMatch($expected, $actual);
    }

    /**
     * Configured warehouse display name for SQL filters (e.g. "DDS728").
     */
    public function getConfiguredWarehouseNameForFilter(): ?string
    {
        $warehouseId = $this->shipStationInventory->getConfiguredWarehouseId();

        if ($warehouseId === '') {
            return null;
        }

        $name = $this->shipStationInventory->getWarehouseName($warehouseId);

        return $name !== null && $name !== '' ? $name : null;
    }
}
