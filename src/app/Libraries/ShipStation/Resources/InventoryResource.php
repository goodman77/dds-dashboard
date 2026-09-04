<?php

declare(strict_types=1);

namespace App\Libraries\ShipStation\Resources;

use App\Libraries\ShipStation\Exceptions\ShipStationApiException;
use App\Libraries\ShipStation\ShipStationClient;
use Config\ShipStation as ShipStationConfig;

class InventoryResource
{
    /** @var array<string, string|null> */
    private array $locationNameCache = [];

    /** @var array<string, string|null> */
    private array $warehouseNameCache = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $warehouseLocationListCache = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $skuInventoryCache = [];

    /** @var list<array{inventory_warehouse_id: string, name: string, created_at: string|null}>|null */
    private ?array $warehouseListCache = null;

    private ?string $resolvedWarehouseId = null;

    public function __construct(
        private readonly ShipStationClient $client,
        private readonly ShipStationConfig $config,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getInventoryBySku(string $sku, bool $restrictToConfiguredWarehouse = true): array
    {
        $sku = trim($sku);

        if ($sku === '') {
            return [];
        }

        $query = [
            'sku'       => $sku,
            'group_by'  => 'location',
            'page_size' => 100,
        ];

        $configuredWarehouseId = $this->getConfiguredWarehouseId();

        if ($restrictToConfiguredWarehouse && $configuredWarehouseId !== '') {
            $query['inventory_warehouse_id'] = $configuredWarehouseId;
        }

        $response = $this->client->get('inventory', $query);
        $items    = $response['inventory'] ?? $response['inventories'] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    public function getConfiguredWarehouseId(): string
    {
        if ($this->resolvedWarehouseId !== null) {
            return $this->resolvedWarehouseId;
        }

        $configured = strtolower(trim($this->config->warehouseId));

        if ($configured !== '' && $configured !== 'latest' && $configured !== 'auto') {
            $this->resolvedWarehouseId = trim($this->config->warehouseId);

            return $this->resolvedWarehouseId;
        }

        $latest = $this->findLatestWarehouseId();
        $this->resolvedWarehouseId = $latest ?? '';

        return $this->resolvedWarehouseId;
    }

    /**
     * @return list<array{inventory_warehouse_id: string, name: string, created_at: string|null}>
     */
    public function listWarehouses(bool $forceRefresh = false): array
    {
        if (! $forceRefresh && $this->warehouseListCache !== null) {
            return $this->warehouseListCache;
        }

        $warehouses = [];
        $response   = $this->client->get('inventory_warehouses', ['page_size' => 100]);
        $pages      = 0;

        while (is_array($response) && $pages < 20) {
            $items = $response['inventory_warehouses'] ?? $response['warehouses'] ?? [];

            if (! is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $warehouseId = $this->stringOrNull($item['inventory_warehouse_id'] ?? $item['warehouse_id'] ?? null);
                $name        = $this->stringOrNull($item['name'] ?? null);

                if ($warehouseId === null) {
                    continue;
                }

                if ($name !== null) {
                    $this->warehouseNameCache[$warehouseId] = $name;
                }

                $warehouses[] = [
                    'inventory_warehouse_id' => $warehouseId,
                    'name'                   => $name ?? $warehouseId,
                    'created_at'             => $this->stringOrNull($item['created_at'] ?? null),
                ];
            }

            $pages++;
            $nextHref = $this->stringOrNull($response['links']['next']['href'] ?? null);

            if ($nextHref === null) {
                break;
            }

            $response = $this->client->getFromLink($nextHref);
        }

        $this->warehouseListCache = $warehouses;

        return $warehouses;
    }

    public function findLatestWarehouseId(): ?string
    {
        $warehouses = $this->listWarehouses();

        if ($warehouses === []) {
            return null;
        }

        usort($warehouses, static function (array $left, array $right): int {
            $leftCreated  = strtotime((string) ($left['created_at'] ?? '')) ?: 0;
            $rightCreated = strtotime((string) ($right['created_at'] ?? '')) ?: 0;

            if ($leftCreated !== $rightCreated) {
                return $rightCreated <=> $leftCreated;
            }

            return strcmp((string) $right['inventory_warehouse_id'], (string) $left['inventory_warehouse_id']);
        });

        return $warehouses[0]['inventory_warehouse_id'] ?? null;
    }

    public function getConfiguredWarehouseLabel(): string
    {
        $warehouseId = $this->getConfiguredWarehouseId();

        if ($warehouseId === '') {
            return 'the configured warehouse';
        }

        $name = $this->getWarehouseName($warehouseId);

        return $name !== null && $name !== ''
            ? sprintf('%s (%s)', $name, $warehouseId)
            : $warehouseId;
    }

    /**
     * Pick the best inventory row for a SKU, preferring the sheet's expected bin when provided.
     *
     * @return array{
     *     sku: string,
     *     location_id: string|null,
     *     location_name: string|null,
     *     warehouse_id: string|null,
     *     warehouse_name: string|null,
     *     on_hand: int,
     *     available: int,
     *     in_configured_warehouse: bool
     * }|null
     */
    public function findBestLocationForSku(string $sku, ?string $expectedLocation = null): ?array
    {
        $rows = $this->listInventoryRowsForSku($sku, false);

        if ($rows === []) {
            return null;
        }

        $expectedLocation      = trim((string) $expectedLocation);
        $configuredWarehouseId = $this->getConfiguredWarehouseId();

        $configuredMatch = $this->findMatchingLocationRow($rows, $expectedLocation, $configuredWarehouseId);

        if ($configuredMatch !== null) {
            return $configuredMatch;
        }

        if ($configuredWarehouseId !== '') {
            $configuredRows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => ($row['warehouse_id'] ?? '') === $configuredWarehouseId
                    && ($row['on_hand'] ?? 0) > 0,
            ));

            if ($configuredRows !== []) {
                return $this->pickHighestOnHandRow($configuredRows);
            }
        }

        $anyMatch = $this->findMatchingLocationRow($rows, $expectedLocation, null);

        if ($anyMatch !== null) {
            return $anyMatch;
        }

        $syncWarehouseId = $this->resolveSyncWarehouseId($sku, $expectedLocation);

        if ($syncWarehouseId !== null && $syncWarehouseId !== '') {
            $syncRows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => ($row['warehouse_id'] ?? '') === $syncWarehouseId
                    && ($row['on_hand'] ?? 0) > 0,
            ));

            if ($syncRows !== []) {
                return $this->pickHighestOnHandRow($syncRows);
            }
        }

        $withStock = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['on_hand'] ?? 0) > 0,
        ));

        return $this->pickHighestOnHandRow($withStock !== [] ? $withStock : $rows);
    }

    /**
     * @return list<array{
     *     sku: string,
     *     location_id: string|null,
     *     location_name: string|null,
     *     warehouse_id: string|null,
     *     warehouse_name: string|null,
     *     on_hand: int,
     *     available: int,
     *     in_configured_warehouse: bool
     * }>
     */
    public function listInventoryRowsForSku(string $sku, bool $restrictToConfiguredWarehouse = false): array
    {
        $sku = trim($sku);
        $cacheKey = $this->skuInventoryCacheKey($sku, $restrictToConfiguredWarehouse);

        if ($sku !== '' && isset($this->skuInventoryCache[$cacheKey])) {
            return $this->skuInventoryCache[$cacheKey];
        }

        $items = $this->getInventoryBySku($sku, $restrictToConfiguredWarehouse);
        $rows  = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = $this->enrichInventoryItem($sku, $item);

            if ($row === null) {
                continue;
            }

            // Skip leftover qty on a deleted ShipStation bin so one ghost
            // location cannot abort the whole SKU lookup.
            if (($row['location_id'] ?? null) !== null && ($row['warehouse_id'] ?? null) === null) {
                continue;
            }

            $rows[] = $row;
        }

        if ($sku !== '') {
            $this->skuInventoryCache[$cacheKey] = $rows;
        }

        return $rows;
    }

    public function forgetSkuInventory(string $sku): void
    {
        $sku = trim($sku);

        unset(
            $this->skuInventoryCache[$this->skuInventoryCacheKey($sku, false)],
            $this->skuInventoryCache[$this->skuInventoryCacheKey($sku, true)],
        );
    }

    /**
     * @return list<array{
     *     sku: string,
     *     location_id: string|null,
     *     location_name: string|null,
     *     warehouse_id: string|null,
     *     warehouse_name: string|null,
     *     on_hand: int,
     *     available: int,
     *     in_configured_warehouse: bool
     * }>
     */
    public function listInventoryInWarehouse(string $sku, string $warehouseId): array
    {
        $warehouseId = trim($warehouseId);

        if ($warehouseId === '') {
            return [];
        }

        return array_values(array_filter(
            $this->listInventoryRowsForSku($sku, false),
            static fn (array $row): bool => ($row['warehouse_id'] ?? '') === $warehouseId
                && ($row['on_hand'] ?? 0) > 0,
        ));
    }

    public function resolveSyncWarehouseId(string $sku, ?string $expectedLocation = null): ?string
    {
        $rows                  = $this->listInventoryRowsForSku($sku, false);
        $expectedLocation      = trim((string) $expectedLocation);
        $configuredWarehouseId = $this->getConfiguredWarehouseId();

        if ($rows === []) {
            return null;
        }

        $configuredMatch = $this->findMatchingLocationRow($rows, $expectedLocation, $configuredWarehouseId);

        if ($configuredMatch !== null) {
            return $configuredMatch['warehouse_id'];
        }

        if ($configuredWarehouseId !== '') {
            foreach ($rows as $row) {
                if (($row['warehouse_id'] ?? '') === $configuredWarehouseId && ($row['on_hand'] ?? 0) > 0) {
                    return $configuredWarehouseId;
                }
            }
        }

        $anyMatch = $this->findMatchingLocationRow($rows, $expectedLocation, null);

        if ($anyMatch !== null) {
            return $anyMatch['warehouse_id'];
        }

        $totals = [];

        foreach ($rows as $row) {
            if (($row['on_hand'] ?? 0) <= 0 || ($row['warehouse_id'] ?? '') === '') {
                continue;
            }

            $totals[$row['warehouse_id']] = ($totals[$row['warehouse_id']] ?? 0) + (int) $row['on_hand'];
        }

        if ($totals === []) {
            return $rows[0]['warehouse_id'] ?? null;
        }

        arsort($totals);

        return (string) array_key_first($totals);
    }

    /**
     * Pick the best inventory row for a SKU (highest on-hand, then first result).
     *
     * @return array{
     *     sku: string,
     *     location_id: string|null,
     *     location_name: string|null,
     *     warehouse_id: string|null,
     *     warehouse_name: string|null,
     *     on_hand: int,
     *     available: int,
     *     in_configured_warehouse: bool
     * }|null
     */
    public function findPrimaryLocationForSku(string $sku): ?array
    {
        return $this->findBestLocationForSku($sku);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{
     *     sku: string,
     *     location_id: string|null,
     *     location_name: string|null,
     *     warehouse_id: string|null,
     *     warehouse_name: string|null,
     *     on_hand: int,
     *     available: int,
     *     in_configured_warehouse: bool
     * }|null
     */
    private function enrichInventoryItem(string $sku, array $item): ?array
    {
        $locationId  = $this->stringOrNull($item['inventory_location_id'] ?? $item['location_id'] ?? null);
        $warehouseId = $this->stringOrNull($item['inventory_warehouse_id'] ?? $item['warehouse_id'] ?? null);
        $locationName = $this->stringOrNull($item['location_name'] ?? $item['name'] ?? null);
        $warehouseName = $this->stringOrNull($item['warehouse_name'] ?? $item['inventory_warehouse_name'] ?? null);

        if ($locationId !== null && $locationName !== null) {
            $this->locationNameCache[$locationId] = $locationName;
        }

        if ($warehouseId === null && $locationId !== null) {
            $warehouseId = $this->getWarehouseIdForLocation($locationId);
        }

        if ($warehouseId !== null && $warehouseName !== null) {
            $this->warehouseNameCache[$warehouseId] = $warehouseName;
        }

        $configuredWarehouseId = $this->getConfiguredWarehouseId();

        return [
            'sku'                     => trim($sku),
            'location_id'             => $locationId,
            'location_name'           => $locationName ?? ($locationId !== null ? $this->getLocationName($locationId) : null),
            'warehouse_id'            => $warehouseId,
            'warehouse_name'          => $warehouseName ?? ($warehouseId !== null ? $this->getWarehouseName($warehouseId) : null),
            'on_hand'                 => max(0, (int) ($item['on_hand'] ?? $item['quantity'] ?? 0)),
            'available'               => max(0, (int) ($item['available'] ?? $item['available_quantity'] ?? 0)),
            'in_configured_warehouse' => $configuredWarehouseId !== ''
                && $warehouseId !== null
                && $warehouseId === $configuredWarehouseId,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    private function findMatchingLocationRow(array $rows, string $expectedLocation, ?string $warehouseId): ?array
    {
        $expectedLocation = trim($expectedLocation);
        $warehouseId      = trim((string) $warehouseId);

        if ($expectedLocation === '') {
            return null;
        }

        foreach ($rows as $row) {
            if (($row['on_hand'] ?? 0) <= 0) {
                continue;
            }

            if ($warehouseId !== '' && ($row['warehouse_id'] ?? '') !== $warehouseId) {
                continue;
            }

            if ($this->locationNamesMatch($expectedLocation, (string) ($row['location_name'] ?? ''))) {
                return $row;
            }
        }

        return null;
    }

    private function pickHighestOnHandRow(array $rows): array
    {
        usort($rows, static function (array $left, array $right): int {
            return ($right['on_hand'] ?? 0) <=> ($left['on_hand'] ?? 0);
        });

        return $rows[0];
    }

    private function getWarehouseIdForLocation(string $locationId): ?string
    {
        $locationId = trim($locationId);

        if ($locationId === '') {
            return null;
        }

        foreach ($this->warehouseLocationListCache as $warehouseId => $locations) {
            foreach ($locations as $location) {
                if (($location['inventory_location_id'] ?? null) === $locationId) {
                    return (string) $warehouseId;
                }
            }
        }

        try {
            $response = $this->client->get('inventory_locations/' . rawurlencode($locationId));
        } catch (ShipStationApiException) {
            return null;
        }

        $warehouseId = $this->stringOrNull($response['inventory_warehouse_id'] ?? null);
        $name        = $this->stringOrNull($response['name'] ?? null);

        if ($name !== null) {
            $this->locationNameCache[$locationId] = $name;
        }

        return $warehouseId;
    }

    public function getLocationName(string $locationId): ?string
    {
        $locationId = trim($locationId);

        if ($locationId === '') {
            return null;
        }

        if (array_key_exists($locationId, $this->locationNameCache)) {
            return $this->locationNameCache[$locationId];
        }

        foreach ($this->warehouseLocationListCache as $locations) {
            foreach ($locations as $location) {
                if (($location['inventory_location_id'] ?? null) === $locationId) {
                    $name = $this->stringOrNull($location['name'] ?? null);
                    $this->locationNameCache[$locationId] = $name;

                    return $name;
                }
            }
        }

        try {
            $response = $this->client->get('inventory_locations/' . rawurlencode($locationId));
        } catch (ShipStationApiException $exception) {
            if ($this->isMissingLocationError($exception)) {
                $this->locationNameCache[$locationId] = null;

                return null;
            }

            throw $exception;
        }

        $name = $this->stringOrNull($response['name'] ?? null);
        $this->locationNameCache[$locationId] = $name;

        return $name;
    }

    public function getWarehouseName(string $warehouseId): ?string
    {
        $warehouseId = trim($warehouseId);

        if ($warehouseId === '') {
            return null;
        }

        if (array_key_exists($warehouseId, $this->warehouseNameCache)) {
            return $this->warehouseNameCache[$warehouseId];
        }

        $response = $this->client->get('inventory_warehouses/' . rawurlencode($warehouseId));
        $name     = $this->stringOrNull($response['name'] ?? null);
        $this->warehouseNameCache[$warehouseId] = $name;

        return $name;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLocationsForWarehouse(?string $warehouseId = null, bool $forceRefresh = false): array
    {
        $warehouseId = trim($warehouseId ?? $this->getConfiguredWarehouseId());

        if ($warehouseId === '') {
            return [];
        }

        if (! $forceRefresh && isset($this->warehouseLocationListCache[$warehouseId])) {
            return $this->warehouseLocationListCache[$warehouseId];
        }

        $locations = [];

        foreach ($this->iterateLocationPages($warehouseId) as $item) {
            $locationId = $this->stringOrNull($item['inventory_location_id'] ?? $item['location_id'] ?? null);
            $name       = $this->stringOrNull($item['name'] ?? null);

            if ($locationId === null || $name === null) {
                continue;
            }

            $this->locationNameCache[$locationId] = $name;
            $locations[] = [
                'inventory_location_id'    => $locationId,
                'inventory_warehouse_id'   => $warehouseId,
                'name'                     => $name,
            ];
        }

        $this->warehouseLocationListCache[$warehouseId] = $locations;

        return $locations;
    }

    public function clearLocationListCache(): void
    {
        $this->warehouseLocationListCache = [];
    }

    public function findLocationByName(string $locationName, ?string $warehouseId = null, bool $forceRefresh = false): ?array
    {
        $locationName = trim($locationName);
        $warehouseId  = trim($warehouseId ?? $this->getConfiguredWarehouseId());

        if ($locationName === '' || $warehouseId === '') {
            return null;
        }

        foreach ($this->listLocationsForWarehouse($warehouseId, $forceRefresh) as $item) {
            $name = $this->stringOrNull($item['name'] ?? null);

            if ($name === null || ! $this->locationNamesMatch($locationName, $name)) {
                continue;
            }

            return [
                'inventory_location_id'  => (string) $item['inventory_location_id'],
                'inventory_warehouse_id' => $warehouseId,
                'name'                   => $name,
            ];
        }

        return null;
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function iterateLocationPages(string $warehouseId): \Generator
    {
        $response = $this->client->get('inventory_locations', [
            'inventory_warehouse_id' => $warehouseId,
            'page_size'              => 250,
        ]);
        $pages = 0;

        while (is_array($response) && $pages < 100) {
            $items = $response['inventory_locations'] ?? $response['locations'] ?? [];

            if (! is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }

            $pages++;
            $nextHref = $this->stringOrNull($response['links']['next']['href'] ?? null);

            if ($nextHref === null) {
                break;
            }

            $response = $this->client->getFromLink($nextHref);
        }
    }

    public function locationNamesMatch(string $left, string $right): bool
    {
        if ($this->normalizeLocationName($left) === $this->normalizeLocationName($right)) {
            return true;
        }

        $leftSegments  = $this->locationSegments($left);
        $rightSegments = $this->locationSegments($right);

        return $leftSegments !== [] && $leftSegments === $rightSegments;
    }

    /**
     * @return list<string>
     */
    private function locationSegments(string $location): array
    {
        $location = $this->normalizeLocationName($location);
        $parts    = preg_split('/\s+-\s+/', $location) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $part): string => trim($part), $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * @return array{inventory_location_id: string, name: string, created: bool}
     */
    public function findOrCreateLocationByName(string $locationName, ?string $warehouseId = null): array
    {
        $locationName = trim($locationName);
        $warehouseId  = trim($warehouseId ?? $this->getConfiguredWarehouseId());

        if ($locationName === '') {
            throw new \InvalidArgumentException('Location name is required.');
        }

        if ($warehouseId === '') {
            throw new \InvalidArgumentException('ShipStation warehouse ID is not configured.');
        }

        $existing = $this->findLocationByName($locationName, $warehouseId);

        if ($existing !== null) {
            return [
                'inventory_location_id' => (string) $existing['inventory_location_id'],
                'name'                  => (string) $existing['name'],
                'created'               => false,
            ];
        }

        try {
            $response = $this->client->post('inventory_locations', [
                'name'                   => $locationName,
                'inventory_warehouse_id' => $warehouseId,
            ]);
        } catch (ShipStationApiException $exception) {
            if ($this->isDuplicateLocationError($exception)) {
                $this->clearLocationListCache();
                $existing = $this->findLocationByName($locationName, $warehouseId, true);

                if ($existing !== null) {
                    return [
                        'inventory_location_id' => (string) $existing['inventory_location_id'],
                        'name'                  => (string) $existing['name'],
                        'created'               => false,
                    ];
                }
            }

            throw $exception;
        }

        $locationId = $this->stringOrNull(
            is_array($response)
                ? ($response['inventory_location_id'] ?? $response['location_id'] ?? null)
                : null,
        );

        if ($locationId === null) {
            $this->clearLocationListCache();
            $created = $this->findLocationByName($locationName, $warehouseId, true);

            if ($created === null) {
                throw new \RuntimeException(sprintf('Could not create ShipStation location "%s".', $locationName));
            }

            $locationId = (string) $created['inventory_location_id'];
        }

        $this->locationNameCache[$locationId] = $locationName;
        $this->warehouseLocationListCache[$warehouseId][] = [
            'inventory_location_id'  => $locationId,
            'inventory_warehouse_id' => $warehouseId,
            'name'                   => $locationName,
        ];

        return [
            'inventory_location_id' => $locationId,
            'name'                  => $locationName,
            'created'               => true,
        ];
    }

    private function isDuplicateLocationError(ShipStationApiException $exception): bool
    {
        return stripos($exception->getMessage(), 'already exists') !== false;
    }

    public function isMissingLocationError(ShipStationApiException $exception): bool
    {
        if (stripos($exception->getMessage(), 'Inventory Location not found') !== false) {
            return true;
        }

        $errors = $exception->getResponseBody()['errors'] ?? [];

        if (! is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $code    = strtolower((string) ($error['error_code'] ?? ''));
            $message = strtolower((string) ($error['message'] ?? ''));

            if ($code === 'invalid_identifier' && str_contains($message, 'location not found')) {
                return true;
            }
        }

        return false;
    }

    public function incrementInventoryAtLocation(string $locationId, string $sku, int $quantity, string $reason = ''): void
    {
        $this->postInventoryUpdate([
            'transaction_type'      => 'increment',
            'inventory_location_id' => $locationId,
            'sku'                   => trim($sku),
            'quantity'              => max(0, $quantity),
            'reason'                => $reason !== '' ? $reason : 'DDS dashboard location sync',
        ]);
        $this->forgetSkuInventory($sku);
    }

    public function adjustInventoryAtLocation(string $locationId, string $sku, int $quantity, string $reason = ''): void
    {
        $this->postInventoryUpdate([
            'transaction_type'      => 'adjust',
            'inventory_location_id' => $locationId,
            'sku'                   => trim($sku),
            'quantity'              => max(0, $quantity),
            'reason'                => $reason !== '' ? $reason : 'DDS dashboard location sync',
        ]);
        $this->forgetSkuInventory($sku);
    }

    public function moveInventoryToLocation(
        string $fromLocationId,
        string $toLocationId,
        string $sku,
        int $quantity,
        string $reason = '',
    ): void {
        $quantity = max(0, $quantity);

        if ($quantity <= 0) {
            return;
        }

        $this->postInventoryUpdate([
            'transaction_type'          => 'modify',
            'inventory_location_id'     => $fromLocationId,
            'new_inventory_location_id' => $toLocationId,
            'sku'                       => trim($sku),
            'quantity'                  => $quantity,
            'reason'                    => $reason !== '' ? $reason : 'DDS dashboard location sync',
        ]);
        $this->forgetSkuInventory($sku);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postInventoryUpdate(array $payload): void
    {
        $this->client->post('inventory', $payload);
    }

    private function skuInventoryCacheKey(string $sku, bool $restrictToConfiguredWarehouse): string
    {
        return ($restrictToConfiguredWarehouse ? '1' : '0') . ':' . $sku;
    }

    public function normalizeLocationName(string $location): string
    {
        $location = preg_replace('/\s+/', ' ', trim($location)) ?? trim($location);

        return strtoupper(str_replace([' - ', '-'], ' - ', $location));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
