<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\ShipStation\Exceptions\ShipStationApiException;
use App\Libraries\ShipStation\Resources\InventoryResource;
use App\Models\InventoryModel;

class ShipStationLocationSyncService
{
    public function __construct(
        private readonly InventoryModel $inventory,
        private readonly InventoryResource $shipStationInventory,
        private readonly ShipStationLocationCheckService $locationCheck,
    ) {
    }

    /**
     * Sync every inventory row in a list — same logic as the row pin button ({@see syncRow()}).
     *
     * @param list<array<string, mixed>> $rows
     * @param callable(int $processed, int $total, array<string, mixed> $totals): void|null $onProgress
     * @param callable(): bool|null $shouldCancel
     * @param callable(string $message, string $color): void|null $onLogLine
     *
     * @return array{
     *     sheet_name: string,
     *     total: int,
     *     processed: int,
     *     synced: int,
     *     matched: int,
     *     mismatched: int,
     *     missing: int,
     *     empty_location: int,
     *     errors: list<string>
     * }
     */
    public function syncManyRows(
        array $rows,
        string $scope,
        ?float $delaySeconds = null,
        ?callable $onProgress = null,
        ?callable $shouldCancel = null,
        ?callable $onLogLine = null,
    ): array {
        $delaySeconds ??= (float) config('ShipStation')->requestDelaySeconds;
        $total          = count($rows);
        $processed      = 0;
        $synced         = 0;
        $matched        = 0;
        $mismatched     = 0;
        $missing        = 0;
        $emptyLocation  = 0;
        $errors         = [];

        $emitProgress = static function () use (
            &$processed,
            &$total,
            &$synced,
            &$matched,
            &$mismatched,
            &$missing,
            &$emptyLocation,
            &$errors,
            $onProgress,
        ): void {
            if ($onProgress === null) {
                return;
            }

            $onProgress($processed, $total, [
                'synced'         => $synced,
                'matched'        => $matched,
                'mismatched'     => $mismatched,
                'missing'        => $missing,
                'empty_location' => $emptyLocation,
                'errors'         => $errors,
            ]);
        };

        $emitProgress();
        $this->warmupConfiguredWarehouseLocations();

        foreach ($rows as $row) {
            if ($shouldCancel !== null && $shouldCancel()) {
                return [
                    'sheet_name'     => $scope,
                    'total'          => $total,
                    'processed'      => $processed,
                    'synced'         => $synced,
                    'matched'        => $matched,
                    'mismatched'     => $mismatched,
                    'missing'        => $missing,
                    'empty_location' => $emptyLocation,
                    'errors'         => $errors,
                    'cancelled'      => true,
                ];
            }

            $rowId = (int) ($row['id'] ?? 0);
            $sku   = trim((string) ($row['sku'] ?? ''));

            if ($rowId <= 0 || $sku === '') {
                continue;
            }

            $result   = $this->syncRow($rowId);
            $processed++;
            $skuLabel = $scope === '*'
                ? sprintf('%s (%s)', $sku, (string) ($row['sheet_name'] ?? ''))
                : $sku;
            $linePrefix = sprintf('[%d/%d] %s', $processed, $total, $skuLabel);

            if (($result['shipstation_exists'] ?? null) === false) {
                $missing++;
                $onLogLine?->__invoke($linePrefix . ' — not in ShipStation', 'yellow');
            } elseif (! empty($result['synced'])) {
                $synced++;

                if (($result['in_configured_warehouse'] ?? true) === false) {
                    $mismatched++;
                    $onLogLine?->__invoke(sprintf(
                        '%s — moved to %s (wrong warehouse %s)',
                        $linePrefix,
                        (string) ($result['shipstation_location'] ?? $result['expected_location'] ?? ''),
                        (string) ($result['shipstation_warehouse'] ?? 'unknown warehouse'),
                    ), 'yellow');
                } else {
                    $matched++;
                    $onLogLine?->__invoke(sprintf(
                        '%s — moved to %s',
                        $linePrefix,
                        (string) ($result['shipstation_location'] ?? $result['expected_location'] ?? ''),
                    ), 'green');
                }
            } elseif ($result['ok'] && ($result['location_matches'] ?? null) === true) {
                $matched++;
                $onLogLine?->__invoke(sprintf(
                    '%s — location match (%s)',
                    $linePrefix,
                    (string) ($result['shipstation_location'] ?? ''),
                ), 'green');
            } elseif (($result['in_configured_warehouse'] ?? true) === false) {
                $mismatched++;
                $onLogLine?->__invoke(sprintf(
                    '%s — wrong warehouse (%s at %s)',
                    $linePrefix,
                    (string) ($result['shipstation_warehouse'] ?? 'unknown warehouse'),
                    (string) ($result['shipstation_location'] ?? 'unknown location'),
                ), 'yellow');
            } elseif ($result['ok'] && ($result['location_matches'] ?? null) === false) {
                $mismatched++;
                $onLogLine?->__invoke(sprintf(
                    '%s — mismatch (ShipStation: %s, expected: %s)',
                    $linePrefix,
                    (string) ($result['shipstation_location'] ?? '—'),
                    (string) ($result['expected_location'] ?? '—'),
                ), 'yellow');
            } elseif ($result['ok']) {
                $emptyLocation++;
                $onLogLine?->__invoke($linePrefix . ' — in ShipStation but location empty', 'yellow');
            } else {
                $message  = $result['message'] ?? 'ShipStation sync failed.';
                $errors[] = sprintf('SKU %s: %s', $sku, $message);
                $onLogLine?->__invoke(sprintf('%s — error: %s', $linePrefix, $message), 'red');
            }

            $emitProgress();

            if ($this->shouldThrottleAfterResult($result)) {
                $this->sleepBetweenRequests($delaySeconds);
            }
        }

        return [
            'sheet_name'     => $scope,
            'total'          => $total,
            'processed'      => $processed,
            'synced'         => $synced,
            'matched'        => $matched,
            'mismatched'     => $mismatched,
            'missing'        => $missing,
            'empty_location' => $emptyLocation,
            'errors'         => $errors,
        ];
    }

    /**
     * Move an existing ShipStation SKU to the expected bin location (will not create new SKUs).
     *
     * @return array<string, mixed>
     */
    public function syncRow(int $id): array
    {
        $row = $this->inventory->find($id);

        if ($row === null) {
            return ['ok' => false, 'message' => 'Inventory row not found.'];
        }

        $sku = trim((string) ($row['sku'] ?? ''));

        if ($sku === '') {
            return ['ok' => false, 'message' => 'This row has no SKU to sync.'];
        }

        $expectedLocation = $this->locationCheck->buildExpectedLocation($row);

        if ($expectedLocation === null) {
            return ['ok' => false, 'message' => 'This row has no rack/bin location to sync.'];
        }

        $configuredWarehouseId = $this->shipStationInventory->getConfiguredWarehouseId();

        if ($configuredWarehouseId === '') {
            return [
                'ok'      => false,
                'message' => 'Could not resolve the latest ShipStation warehouse. Check the API key or set shipstation.warehouseId in .env.',
            ];
        }

        try {
            $inventoryRows = $this->shipStationInventory->listInventoryRowsForSku($sku, false);

            if ($inventoryRows === []) {
                return $this->recordObservedState(
                    $id,
                    $sku,
                    $expectedLocation,
                    null,
                    null,
                    0,
                    false,
                    null,
                    true,
                    sprintf('SKU %s is not in ShipStation. Add it in ShipStation first, then sync the location here.', $sku),
                    false,
                );
            }

            $syncWarehouseId = $this->shipStationInventory->resolveSyncWarehouseId($sku, $expectedLocation);

            if ($syncWarehouseId === null || $syncWarehouseId === '') {
                return $this->recordObservedState(
                    $id,
                    $sku,
                    $expectedLocation,
                    null,
                    null,
                    0,
                    false,
                    null,
                    true,
                    sprintf('SKU %s is not in ShipStation. Add it in ShipStation first, then sync the location here.', $sku),
                    false,
                );
            }

            $inConfiguredWarehouse = $syncWarehouseId === $configuredWarehouseId;
            $sources               = $this->shipStationInventory->listInventoryInWarehouse($sku, $syncWarehouseId);

            if ($sources === []) {
                return $this->recordObservedState(
                    $id,
                    $sku,
                    $expectedLocation,
                    null,
                    $this->shipStationInventory->getWarehouseName($syncWarehouseId),
                    0,
                    false,
                    null,
                    $inConfiguredWarehouse,
                    sprintf('SKU %s has no on-hand quantity in ShipStation to sync.', $sku),
                    false,
                );
            }

            $primary     = $this->pickPrimarySource($sources);
            $totalOnHand = $this->sumOnHand($sources);

            if ($this->allStockAtExpectedName($sources, $expectedLocation)) {
                return $this->recordObservedState(
                    $id,
                    $sku,
                    $expectedLocation,
                    $primary['location_name'] ?? $expectedLocation,
                    $primary['warehouse_name'] ?? $this->shipStationInventory->getWarehouseName($syncWarehouseId),
                    $totalOnHand,
                    true,
                    true,
                    $inConfiguredWarehouse,
                    'ShipStation location already matches the sheet.',
                    true,
                );
            }

            $target           = $this->shipStationInventory->findOrCreateLocationByName($expectedLocation, $syncWarehouseId);
            $targetLocationId = $target['inventory_location_id'];

            if ($this->allStockAtLocation($sources, $targetLocationId)) {
                return $this->recordObservedState(
                    $id,
                    $sku,
                    $expectedLocation,
                    (string) ($target['name'] ?? $expectedLocation),
                    $primary['warehouse_name'] ?? $this->shipStationInventory->getWarehouseName($syncWarehouseId),
                    $totalOnHand,
                    true,
                    true,
                    $inConfiguredWarehouse,
                    'ShipStation location already matches the sheet.',
                    true,
                    false,
                    ! empty($target['created']),
                );
            }

            $movedCount  = 0;
            $totalOnHand = 0;

            foreach ($sources as $source) {
                $sourceLocationId = $source['location_id'] ?? null;
                $onHand           = (int) ($source['on_hand'] ?? 0);

                if ($sourceLocationId === null || $onHand <= 0) {
                    continue;
                }

                if (($source['warehouse_id'] ?? null) === null || trim((string) ($source['location_name'] ?? '')) === '') {
                    continue;
                }

                if ($sourceLocationId === $targetLocationId) {
                    $totalOnHand += $onHand;

                    continue;
                }

                $this->shipStationInventory->moveInventoryToLocation(
                    $sourceLocationId,
                    $targetLocationId,
                    $sku,
                    $onHand,
                    sprintf(
                        'Moved SKU %s from %s to %s from DDS dashboard.',
                        $sku,
                        $source['location_name'] ?? $sourceLocationId,
                        $expectedLocation,
                    ),
                );
                $movedCount++;
                $totalOnHand += $onHand;
            }

            $locationCreated = ! empty($target['created']);
            $message         = $movedCount > 0
                ? sprintf(
                    'Moved %s to %s in ShipStation%s.',
                    $sku,
                    $expectedLocation,
                    $movedCount > 1 ? sprintf(' (consolidated from %d old bins)', $movedCount) : '',
                )
                : sprintf('Synced %s to %s in ShipStation.', $sku, $expectedLocation);

            if ($locationCreated) {
                $message .= sprintf(' Created bin location "%s".', $expectedLocation);
            }

            if ($movedCount > 0 || $locationCreated) {
                return $this->recordSuccessfulMove(
                    $id,
                    $expectedLocation,
                    $target,
                    [
                        'warehouse_id'   => $syncWarehouseId,
                        'warehouse_name' => $this->shipStationInventory->getWarehouseName($syncWarehouseId),
                        'on_hand'        => $totalOnHand,
                    ],
                    $this->appendWarehouseNote($message, $inConfiguredWarehouse, $this->shipStationInventory->getWarehouseName($syncWarehouseId)),
                    $inConfiguredWarehouse,
                );
            }

            return $this->recordObservedState(
                $id,
                $sku,
                $expectedLocation,
                $primary['location_name'] ?? null,
                $primary['warehouse_name'] ?? $this->shipStationInventory->getWarehouseName($syncWarehouseId),
                $totalOnHand,
                true,
                $this->locationCheck->locationsMatch($expectedLocation, $primary['location_name'] ?? null),
                $inConfiguredWarehouse,
                $message,
                true,
            );
        } catch (ShipStationApiException $exception) {
            return $this->recordExceptionState($id, $sku, $expectedLocation, $exception);
        } catch (\Throwable $exception) {
            return $this->recordExceptionState($id, $sku, $expectedLocation, $exception);
        }
    }

    /**
     * @param list<array<string, mixed>> $sources
     */
    private function allStockAtLocation(array $sources, string $targetLocationId): bool
    {
        if ($sources === []) {
            return false;
        }

        foreach ($sources as $source) {
            if (($source['location_id'] ?? null) !== $targetLocationId) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{inventory_location_id: string, name: string, created: bool} $target
     * @param array{warehouse_id?: string|null, warehouse_name?: string|null, on_hand?: int} $current
     *
     * @return array<string, mixed>
     */
    private function recordSuccessfulMove(
        int $id,
        string $expectedLocation,
        array $target,
        array $current,
        string $message,
        bool $inConfiguredWarehouse = true,
    ): array {
        $checkedAt     = date('Y-m-d H:i:s');
        $locationName  = (string) ($target['name'] ?? $expectedLocation);
        $warehouseName = $current['warehouse_name'] ?? null;
        $onHand        = (int) ($current['on_hand'] ?? 0);

        $this->inventory->update($id, [
            'shipstation_location'         => $locationName,
            'shipstation_warehouse'        => $warehouseName,
            'shipstation_on_hand'          => $onHand,
            'shipstation_exists'           => 1,
            'shipstation_location_matches' => 1,
            'shipstation_checked_at'       => $checkedAt,
        ]);

        return [
            'ok'                      => true,
            'synced'                  => true,
            'wrote'                   => true,
            'message'                 => $message,
            'shipstation_location'    => $locationName,
            'shipstation_warehouse'   => $warehouseName,
            'shipstation_on_hand'     => $onHand,
            'expected_location'       => $expectedLocation,
            'location_matches'        => true,
            'shipstation_exists'      => true,
            'in_configured_warehouse' => $inConfiguredWarehouse,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordObservedState(
        int $id,
        string $sku,
        ?string $expectedLocation,
        ?string $locationName,
        ?string $warehouseName,
        int $onHand,
        bool $exists,
        ?bool $locationMatches,
        bool $inConfiguredWarehouse,
        string $message,
        bool $ok,
        bool $synced = false,
        bool $wrote = false,
    ): array {
        $checkedAt = date('Y-m-d H:i:s');

        $this->inventory->update($id, [
            'shipstation_location'         => $exists ? $locationName : null,
            'shipstation_warehouse'        => $exists ? $warehouseName : null,
            'shipstation_on_hand'          => $exists ? $onHand : null,
            'shipstation_exists'           => $exists ? 1 : 0,
            'shipstation_location_matches' => ! $exists || $locationMatches === null
                ? null
                : ($locationMatches ? 1 : 0),
            'shipstation_checked_at'       => $checkedAt,
        ]);

        return [
            'ok'                      => $ok,
            'synced'                  => $synced,
            'wrote'                   => $wrote,
            'message'                 => $message,
            'shipstation_location'    => $exists ? $locationName : null,
            'shipstation_warehouse'   => $exists ? $warehouseName : null,
            'shipstation_on_hand'     => $exists ? $onHand : null,
            'expected_location'       => $expectedLocation,
            'location_matches'        => $locationMatches,
            'shipstation_exists'      => $exists,
            'in_configured_warehouse' => $inConfiguredWarehouse,
        ];
    }

    /**
     * @param list<array<string, mixed>> $sources
     */
    private function allStockAtExpectedName(array $sources, string $expectedLocation): bool
    {
        if ($sources === []) {
            return false;
        }

        foreach ($sources as $source) {
            $locationName = trim((string) ($source['location_name'] ?? ''));

            if ($locationName === '' || ! $this->shipStationInventory->locationNamesMatch($expectedLocation, $locationName)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $sources
     *
     * @return array<string, mixed>
     */
    private function pickPrimarySource(array $sources): array
    {
        $best = $sources[0];

        foreach ($sources as $source) {
            if ((int) ($source['on_hand'] ?? 0) > (int) ($best['on_hand'] ?? 0)) {
                $best = $source;
            }
        }

        return $best;
    }

    /**
     * @param list<array<string, mixed>> $sources
     */
    private function sumOnHand(array $sources): int
    {
        $total = 0;

        foreach ($sources as $source) {
            $total += max(0, (int) ($source['on_hand'] ?? 0));
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function shouldThrottleAfterResult(array $result): bool
    {
        if (! empty($result['wrote']) || ! empty($result['throttle'])) {
            return true;
        }

        if (($result['shipstation_exists'] ?? null) === false) {
            return false;
        }

        if (($result['ok'] ?? false) && empty($result['synced'])) {
            return false;
        }

        return ! ($result['ok'] ?? false);
    }

    private function warmupConfiguredWarehouseLocations(): void
    {
        $warehouseId = $this->shipStationInventory->getConfiguredWarehouseId();

        if ($warehouseId === '') {
            return;
        }

        try {
            $this->shipStationInventory->listLocationsForWarehouse($warehouseId);
        } catch (ShipStationApiException) {
            // First SKU that needs this warehouse will load locations.
        }
    }

    private function appendWarehouseNote(string $message, bool $inConfiguredWarehouse, ?string $warehouseName): string
    {
        if ($inConfiguredWarehouse) {
            return $message;
        }

        return $message . sprintf(
            ' Updated in warehouse %s (not %s) — shipping labels using that warehouse will show the new bin.',
            $warehouseName ?? 'where stock was found',
            $this->shipStationInventory->getConfiguredWarehouseLabel(),
        );
    }

    /**
     * Persist a ShipStation status even when the API call fails, so the row
     * does not stay stuck on "Not checked".
     *
     * @return array<string, mixed>
     */
    private function recordExceptionState(
        int $id,
        string $sku,
        ?string $expectedLocation,
        \Throwable $exception,
    ): array {
        $message = $exception->getMessage();

        try {
            $best = $this->shipStationInventory->findBestLocationForSku($sku, $expectedLocation);
        } catch (ShipStationApiException) {
            $best = null;
        }

        if ($best !== null) {
            $result = $this->recordObservedState(
                $id,
                $sku,
                $expectedLocation,
                $best['location_name'] ?? null,
                $best['warehouse_name'] ?? null,
                (int) ($best['on_hand'] ?? 0),
                true,
                $this->locationCheck->locationsMatch($expectedLocation, $best['location_name'] ?? null),
                (bool) ($best['in_configured_warehouse'] ?? false),
                $message,
                false,
            );
            $result['throttle'] = true;

            return $result;
        }

        $checkedAt = date('Y-m-d H:i:s');

        $this->inventory->update($id, [
            'shipstation_location'         => null,
            'shipstation_warehouse'        => null,
            'shipstation_on_hand'          => null,
            'shipstation_exists'           => 0,
            'shipstation_location_matches' => null,
            'shipstation_checked_at'       => $checkedAt,
        ]);

        return [
            'ok'                      => false,
            'wrote'                   => false,
            'throttle'                => true,
            'message'                 => $message,
            'expected_location'       => $expectedLocation,
            'shipstation_exists'      => false,
            'shipstation_location'    => null,
            'shipstation_warehouse'   => null,
            'shipstation_on_hand'     => null,
            'location_matches'        => null,
            'in_configured_warehouse' => null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function existingShipStationState(array $row): array
    {
        $exists = array_key_exists('shipstation_exists', $row) && $row['shipstation_exists'] !== null
            ? (bool) (int) $row['shipstation_exists']
            : null;

        return [
            'shipstation_location'    => $row['shipstation_location'] ?? null,
            'shipstation_warehouse'   => $row['shipstation_warehouse'] ?? null,
            'shipstation_on_hand'     => isset($row['shipstation_on_hand']) ? (int) $row['shipstation_on_hand'] : null,
            'shipstation_exists'      => $exists,
            'location_matches'        => $this->locationCheck->resolveLocationMatchForRow($row),
            'in_configured_warehouse' => $this->locationCheck->resolveInConfiguredWarehouseForRow($row),
        ];
    }

    private function sleepBetweenRequests(float $delaySeconds): void
    {
        if ($delaySeconds <= 0) {
            return;
        }

        usleep((int) round($delaySeconds * 1_000_000));
    }
}
