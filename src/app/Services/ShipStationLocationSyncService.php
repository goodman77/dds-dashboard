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
            $this->sleepBetweenRequests($delaySeconds);
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

        $configuredWarehouseId = trim((string) config('ShipStation')->warehouseId);

        if ($configuredWarehouseId === '') {
            return [
                'ok'      => false,
                'message' => 'ShipStation warehouse ID is not configured. Set shipstation.warehouseId in .env.',
            ];
        }

        try {
            $syncWarehouseId = $this->shipStationInventory->resolveSyncWarehouseId($sku, $expectedLocation);

            if ($syncWarehouseId === null || $syncWarehouseId === '') {
                $check = $this->locationCheck->checkRow($id);

                return array_merge($check, [
                    'ok'                 => false,
                    'synced'             => false,
                    'shipstation_exists' => false,
                    'message'            => sprintf(
                        'SKU %s is not in ShipStation. Add it in ShipStation first, then sync the location here.',
                        $sku,
                    ),
                ]);
            }

            $inConfiguredWarehouse = $syncWarehouseId === $configuredWarehouseId;
            $target                = $this->shipStationInventory->findOrCreateLocationByName($expectedLocation, $syncWarehouseId);
            $targetLocationId      = $target['inventory_location_id'];
            $sources               = $this->shipStationInventory->listInventoryInWarehouse($sku, $syncWarehouseId);

            if ($sources === []) {
                $check = $this->locationCheck->checkRow($id);

                return array_merge($check, [
                    'ok'                 => false,
                    'synced'             => false,
                    'shipstation_exists' => false,
                    'message'            => sprintf(
                        'SKU %s has no on-hand quantity in ShipStation to sync.',
                        $sku,
                    ),
                ]);
            }

            if ($this->allStockAtLocation($sources, $targetLocationId)) {
                $check = $this->locationCheck->checkRow($id);

                return array_merge($check, [
                    'synced'  => false,
                    'message' => 'ShipStation location already matches the sheet.',
                ]);
            }

            $movedCount  = 0;
            $totalOnHand = 0;

            foreach ($sources as $source) {
                $sourceLocationId = $source['location_id'] ?? null;
                $onHand           = (int) ($source['on_hand'] ?? 0);

                if ($sourceLocationId === null || $onHand <= 0) {
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

            $check = $this->locationCheck->checkRow($id);

            return array_merge($check, [
                'ok'      => $check['ok'] ?? false,
                'synced'  => false,
                'message' => $message,
            ]);
        } catch (ShipStationApiException $exception) {
            return array_merge($this->existingShipStationState($row), [
                'ok'                => false,
                'message'           => $exception->getMessage(),
                'expected_location' => $expectedLocation,
            ]);
        } catch (\Throwable $exception) {
            return array_merge($this->existingShipStationState($row), [
                'ok'                => false,
                'message'           => $exception->getMessage(),
                'expected_location' => $expectedLocation,
            ]);
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
