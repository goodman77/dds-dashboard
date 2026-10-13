<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\Net32\Exceptions\Net32ApiException;
use App\Libraries\Net32\Net32Stores;
use App\Libraries\Net32\Resources\ProductsResource;
use App\Models\InventoryModel;
use Config\Net32 as Net32Config;
use GuzzleHttp\Promise\Utils;

class InventoryQuantityCheckService
{
    public function __construct(
        private readonly InventoryModel $inventory,
    ) {
    }

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     quantity?: int,
     *     previous_quantity?: int,
     *     qty_lv?: int|null,
     *     qty_brook?: int|null,
     *     qty_coral?: int|null,
     *     sku_net32_exists?: bool|null
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

        $lookup = $this->lookupSkuAcrossStores($sku);

        return $this->applyStoreLookupToRow($row, $lookup);
    }

    /**
     * Live Net32 lookup for one SKU across all configured stores (no DB write).
     *
     * @return array{
     *     sku: string,
     *     checked_ok: bool,
     *     found_any: bool,
     *     checked_at: string,
     *     stores: array<string, array{ok: bool, found: bool, quantity: int|null, error: string|null}>,
     *     parts: list<string>,
     *     errors: list<string>
     * }
     */
    public function lookupSkuAcrossStores(string $sku): array
    {
        $sku = trim($sku);
        $config = $this->net32Config();
        $stores = $config->configuredStoreIds();
        $checkedAt = date('Y-m-d H:i:s');
        $parts = [];
        $errors = [];
        $storeResults = [];
        $foundAny = false;
        $checkedOk = false;

        if ($sku === '' || $stores === []) {
            return [
                'sku'        => $sku,
                'checked_ok' => false,
                'found_any'  => false,
                'checked_at' => $checkedAt,
                'stores'     => [],
                'parts'      => $stores === [] ? ['No Net32 store API keys are configured.'] : [],
                'errors'     => $stores === [] ? ['No Net32 store API keys are configured.'] : [],
            ];
        }

        $promises = [];

        foreach ($stores as $store) {
            $promises[$store] = $this->productsFor($store)->findOfferByVpCodeAsync($sku);
        }

        $settled = Utils::settle($promises)->wait();

        foreach ($stores as $store) {
            $outcome = $settled[$store] ?? ['state' => 'rejected', 'reason' => null];

            if (($outcome['state'] ?? '') !== 'fulfilled') {
                $reason  = $outcome['reason'] ?? null;
                $message = $reason instanceof \Throwable
                    ? $reason->getMessage()
                    : 'Net32 check failed.';
                $errors[] = sprintf('%s: %s', Net32Stores::label($store), $message);
                $parts[]  = sprintf('%s error', Net32Stores::label($store));
                $storeResults[$store] = [
                    'ok'       => false,
                    'found'    => false,
                    'quantity' => null,
                    'error'    => $message,
                ];

                continue;
            }

            $checkedOk = true;
            $offer     = $outcome['value'] ?? null;

            if (! is_array($offer)) {
                $parts[] = sprintf('%s not found', Net32Stores::label($store));
                $storeResults[$store] = [
                    'ok'       => true,
                    'found'    => false,
                    'quantity' => null,
                    'error'    => null,
                ];

                continue;
            }

            $quantity = max(0, (int) ($offer['quantity'] ?? 0));
            $foundAny = true;
            $parts[]  = sprintf('%s %s', Net32Stores::label($store), number_format($quantity));
            $storeResults[$store] = [
                'ok'       => true,
                'found'    => true,
                'quantity' => $quantity,
                'error'    => null,
            ];
        }

        return [
            'sku'        => $sku,
            'checked_ok' => $checkedOk,
            'found_any'  => $foundAny,
            'checked_at' => $checkedAt,
            'stores'     => $storeResults,
            'parts'      => $parts,
            'errors'     => $errors,
        ];
    }

    /**
     * Apply a shared Net32 lookup result to one inventory row.
     *
     * @param array<string, mixed> $row
     * @param array{
     *     sku: string,
     *     checked_ok: bool,
     *     found_any: bool,
     *     checked_at: string,
     *     stores: array<string, array{ok: bool, found: bool, quantity: int|null, error: string|null}>,
     *     parts: list<string>,
     *     errors: list<string>
     * } $lookup
     *
     * @return array{
     *     ok: bool,
     *     message: string,
     *     quantity?: int,
     *     previous_quantity?: int,
     *     qty_lv?: int|null,
     *     qty_brook?: int|null,
     *     qty_coral?: int|null,
     *     sku_net32_exists?: bool|null
     * }
     */
    public function applyStoreLookupToRow(array $row, array $lookup): array
    {
        $id = (int) ($row['id'] ?? 0);
        $sku = trim((string) ($row['sku'] ?? ($lookup['sku'] ?? '')));
        $previousQuantity = (int) ($row['quantity'] ?? 0);

        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Inventory row not found.'];
        }

        if (! ($lookup['checked_ok'] ?? false)) {
            return [
                'ok'                => false,
                'message'           => ($lookup['errors'] ?? []) !== []
                    ? implode(' ', $lookup['errors'])
                    : 'Could not check Net32 quantities.',
                'previous_quantity' => $previousQuantity,
                'quantity'          => $previousQuantity,
                'qty_lv'            => $this->nullableInt($row['qty_lv'] ?? $previousQuantity),
                'qty_brook'         => $this->nullableInt($row['qty_brook'] ?? null),
                'qty_coral'         => $this->nullableInt($row['qty_coral'] ?? null),
                ...$this->existsPayload($row),
            ];
        }

        $update = [
            'net32_checked_at' => (string) ($lookup['checked_at'] ?? date('Y-m-d H:i:s')),
        ];
        $foundAny = (bool) ($lookup['found_any'] ?? false);
        $parts    = $lookup['parts'] ?? [];
        $errors   = $lookup['errors'] ?? [];

        foreach (($lookup['stores'] ?? []) as $store => $storeResult) {
            if (! ($storeResult['ok'] ?? false)) {
                continue;
            }

            $column = Net32Stores::qtyColumn((string) $store);
            $existsColumn = Net32Stores::existsColumn((string) $store);

            if (! ($storeResult['found'] ?? false)) {
                $update[$column] = null;
                $update[$existsColumn] = 0;

                continue;
            }

            $update[$column] = max(0, (int) ($storeResult['quantity'] ?? 0));
            $update[$existsColumn] = 1;
        }

        if (array_key_exists('qty_lv', $update) && $update['qty_lv'] !== null) {
            $update['quantity'] = (int) $update['qty_lv'];
        }

        $update['sku_net32_exists'] = $foundAny ? 1 : 0;

        if (! $this->inventory->update($id, $update)) {
            return ['ok' => false, 'message' => 'Could not save the updated quantity.'];
        }

        $fresh    = $this->inventory->find($id) ?? array_merge($row, $update);
        $quantity = (int) ($fresh['quantity'] ?? $previousQuantity);
        $message  = $foundAny
            ? 'Net32 qty: ' . implode(' · ', $parts) . '.'
            : sprintf('SKU %s was not found in any Net32 store.', $sku);

        if ($errors !== []) {
            $message .= ' ' . implode(' ', $errors);
        }

        return [
            'ok'                => $foundAny,
            'message'           => $message,
            'quantity'          => $quantity,
            'previous_quantity' => $previousQuantity,
            'qty_lv'            => $this->nullableInt($fresh['qty_lv'] ?? null),
            'qty_brook'         => $this->nullableInt($fresh['qty_brook'] ?? null),
            'qty_coral'         => $this->nullableInt($fresh['qty_coral'] ?? null),
            ...$this->existsPayload($fresh),
        ];
    }

    /**
     * @param array<string, array<string, int>> $catalogs
     * @param array<string, bool> $downloaded
     *
     * @return array{
     *     ok: bool,
     *     updated: bool,
     *     missing: bool,
     *     quantity: int,
     *     previous_quantity: int,
     *     sku_net32_exists: bool
     * }
     */
    public function applyCatalogToRow(array $row, array $catalogs, array $downloaded, string $checkedAt): array
    {
        $rowId            = (int) ($row['id'] ?? 0);
        $sku              = strtolower(trim((string) ($row['sku'] ?? '')));
        $previousQuantity = (int) ($row['quantity'] ?? 0);

        if ($rowId <= 0 || $sku === '') {
            return [
                'ok'                => false,
                'updated'           => false,
                'missing'           => false,
                'quantity'          => $previousQuantity,
                'previous_quantity' => $previousQuantity,
                'sku_net32_exists'  => (bool) ($row['sku_net32_exists'] ?? false),
            ];
        }

        $update   = ['net32_checked_at' => $checkedAt];
        $foundAny = false;
        $changed  = false;

        foreach (Net32Stores::ids() as $store) {
            if (! ($downloaded[$store] ?? false)) {
                continue;
            }

            $column   = Net32Stores::qtyColumn($store);
            $previous = $this->nullableInt($row[$column] ?? ($store === Net32Stores::LV ? $previousQuantity : null));
            $found    = array_key_exists($sku, $catalogs[$store] ?? []);
            $next     = $found ? max(0, (int) $catalogs[$store][$sku]) : null;

            $existsColumn = Net32Stores::existsColumn($store);
            $update[$column] = $next;
            $update[$existsColumn] = $found ? 1 : 0;

            if ($found) {
                $foundAny = true;
            }

            if ($previous !== $next) {
                $changed = true;
            }

            $previousExists = $this->nullableBool($row[$existsColumn] ?? null);

            if ($previousExists !== (bool) $update[$existsColumn]) {
                $changed = true;
            }
        }

        if (array_key_exists('qty_lv', $update) && $update['qty_lv'] !== null) {
            $update['quantity'] = (int) $update['qty_lv'];
        }

        $update['sku_net32_exists'] = $foundAny ? 1 : 0;

        if ((int) ($row['sku_net32_exists'] ?? -1) !== (int) $update['sku_net32_exists']) {
            $changed = true;
        }

        if (! $this->inventory->update($rowId, $update)) {
            return [
                'ok'                => false,
                'updated'           => false,
                'missing'           => ! $foundAny,
                'quantity'          => $previousQuantity,
                'previous_quantity' => $previousQuantity,
                'sku_net32_exists'  => $foundAny,
            ];
        }

        return [
            'ok'                => true,
            'updated'           => $changed,
            'missing'           => ! $foundAny,
            'quantity'          => (int) ($update['quantity'] ?? $previousQuantity),
            'previous_quantity' => $previousQuantity,
            'sku_net32_exists'  => $foundAny,
        ];
    }

    /**
     * @return array{exists: bool|null, warning: string|null}
     */
    public function inspectSkuInNet32(string $sku): array
    {
        $sku = trim($sku);

        if ($sku === '') {
            return ['exists' => null, 'warning' => null];
        }

        try {
            $offer = $this->productsFor(Net32Stores::LV)->findOfferByVpCode($sku);
        } catch (Net32ApiException $exception) {
            return [
                'exists'  => null,
                'warning' => sprintf('Could not verify SKU %s in Net32: %s', $sku, $exception->getMessage()),
            ];
        }

        if ($offer === null) {
            return [
                'exists'  => false,
                'warning' => sprintf('Warning: SKU %s was not found in Net32 (LV).', $sku),
            ];
        }

        return ['exists' => true, 'warning' => null];
    }

    /**
     * Push a typed quantity to all configured Net32 stores using the 60/20/20 split.
     *
     * @return array{
     *     ok: bool,
     *     message: string,
     *     pushed: int,
     *     quantities: array{lv: int, brook: int, coral: int},
     *     applied: array<string, int>,
     *     warnings: list<string>
     * }
     */
    /**
     * Push exact store quantities to Net32 (no 60/20/20 split).
     *
     * @param array<string, int> $quantities Keys: lv, brook, coral
     *
     * @return array{
     *     ok: bool,
     *     message: string,
     *     pushed: int,
     *     quantities: array<string, int>,
     *     applied: array<string, int>,
     *     warnings: list<string>
     * }
     */
    public function pushStoreQuantitiesToNet32(string $sku, array $quantities): array
    {
        $sku = trim($sku);
        $normalized = [];

        foreach (Net32Stores::ids() as $store) {
            if (! array_key_exists($store, $quantities)) {
                continue;
            }

            $normalized[$store] = max(0, (int) $quantities[$store]);
        }

        if ($sku === '') {
            return [
                'ok'          => false,
                'message'     => 'This row has no SKU to sync to Net32.',
                'pushed'      => 0,
                'quantities'  => $normalized,
                'applied'     => [],
                'warnings'    => [],
            ];
        }

        if ($normalized === []) {
            return [
                'ok'          => false,
                'message'     => 'No store quantities were provided to sync.',
                'pushed'      => 0,
                'quantities'  => [],
                'applied'     => [],
                'warnings'    => [],
            ];
        }

        $configured = $this->net32Config()->configuredStoreIds();
        $stores     = array_values(array_intersect($configured, array_keys($normalized)));

        if ($stores === []) {
            return [
                'ok'          => false,
                'message'     => 'No Net32 store API keys are configured for the selected stores.',
                'pushed'      => 0,
                'quantities'  => $normalized,
                'applied'     => [],
                'warnings'    => [],
            ];
        }

        $applied  = [];
        $warnings = [];
        $lvFailed = false;
        $lvError  = null;

        foreach ($stores as $store) {
            $storeQty = $normalized[$store];

            try {
                $this->productsFor($store)->updateInventoryByVpCode($sku, $storeQty);
                $applied[$store] = $storeQty;
            } catch (Net32ApiException $exception) {
                $label = Net32Stores::label($store);

                if ($this->isMissingOffer($exception)) {
                    $warnings[] = sprintf('%s: SKU %s was not found.', $label, $sku);

                    continue;
                }

                $message = sprintf('%s: %s', $label, $exception->getMessage());

                if ($store === Net32Stores::LV) {
                    $lvFailed = true;
                    $lvError  = $message;
                    break;
                }

                $warnings[] = $message;
            }
        }

        if ($lvFailed) {
            return [
                'ok'          => false,
                'message'     => $lvError ?? 'Could not update LV on Net32.',
                'pushed'      => count($applied),
                'quantities'  => $normalized,
                'applied'     => $applied,
                'warnings'    => $warnings,
            ];
        }

        if ($applied === []) {
            return [
                'ok'          => false,
                'message'     => $warnings !== []
                    ? implode(' ', $warnings)
                    : sprintf('SKU %s was not found on any Net32 store.', $sku),
                'pushed'      => 0,
                'quantities'  => $normalized,
                'applied'     => [],
                'warnings'    => $warnings,
            ];
        }

        $appliedParts = [];

        foreach ($applied as $store => $storeQty) {
            $appliedParts[] = sprintf('%s %s', Net32Stores::label((string) $store), number_format((int) $storeQty));
        }

        $message = sprintf(
            'Quantity synced to Net32 for SKU %s (%s).',
            $sku,
            implode(', ', $appliedParts),
        );

        if ($warnings !== []) {
            $message .= ' ' . implode(' ', $warnings);
        }

        return [
            'ok'          => true,
            'message'     => $message,
            'pushed'      => count($applied),
            'quantities'  => $normalized,
            'applied'     => $applied,
            'warnings'    => $warnings,
        ];
    }

    public function pushQuantityToNet32(string $sku, int $quantity): array
    {
        return $this->pushStoreQuantitiesToNet32($sku, Net32Stores::splitQuantity($quantity));
    }

    /**
     * @param array<string, int> $applied
     *
     * @return array<string, int|null>
     */
    public function recordFromAppliedSplit(array $applied): array
    {
        $record = [];

        foreach (Net32Stores::ids() as $store) {
            if (! array_key_exists($store, $applied)) {
                continue;
            }

            $record[Net32Stores::qtyColumn($store)] = $applied[$store];
            $record[Net32Stores::existsColumn($store)] = 1;
        }

        if (array_key_exists(Net32Stores::LV, $applied)) {
            $record['quantity'] = $applied[Net32Stores::LV];
        }

        if ($applied !== []) {
            $record['sku_net32_exists'] = 1;
        }

        return $record;
    }

    private function productsFor(string $store): ProductsResource
    {
        return service('net32Store', $store)->products();
    }

    private function net32Config(): Net32Config
    {
        return config('Net32');
    }

    private function isMissingOffer(Net32ApiException $exception): bool
    {
        if ($exception->getStatusCode() === 404) {
            return true;
        }

        return (bool) preg_match('/not found|does not exist|no offer/i', $exception->getMessage());
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (bool) (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{
     *     sku_net32_exists: bool|null,
     *     net32_exists_lv: bool|null,
     *     net32_exists_brook: bool|null,
     *     net32_exists_coral: bool|null
     * }
     */
    private function existsPayload(array $row): array
    {
        return [
            'sku_net32_exists'    => $this->nullableBool($row['sku_net32_exists'] ?? null),
            'net32_exists_lv'     => Net32Stores::existsFromRow($row, Net32Stores::LV),
            'net32_exists_brook'  => Net32Stores::existsFromRow($row, Net32Stores::BROOK),
            'net32_exists_coral'  => Net32Stores::existsFromRow($row, Net32Stores::CORAL),
        ];
    }
}
