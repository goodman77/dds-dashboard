<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Net32\Net32Stores;
use CodeIgniter\Model;

class InventoryModel extends Model
{
    protected $table            = 'inventory';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'sheet_name',
        'rack',
        'bin',
        'sku',
        'is_main_sku',
        'name',
        'description',
        'quantity',
        'qty_lv',
        'qty_brook',
        'qty_coral',
        'sku_net32_exists',
        'net32_exists_lv',
        'net32_exists_brook',
        'net32_exists_coral',
        'net32_checked_at',
        'shipstation_location',
        'shipstation_warehouse',
        'shipstation_on_hand',
        'shipstation_exists',
        'shipstation_checked_at',
        'shipstation_location_matches',
        'synced_at',
    ];
    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;
    protected $useTimestamps         = true;

    public function existsSku(string $sheetName, string $rack, string $bin, string $sku): bool
    {
        return $this->findByPosition($sheetName, $rack, $bin, $sku) !== null;
    }

    public function findBySku(string $sku, ?int $excludeId = null): ?array
    {
        $sku = trim($sku);

        if ($sku === '') {
            return null;
        }

        $this->builder = null;

        $builder = $this->where('LOWER(sku)', strtolower($sku));

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        $row = $builder->first();
        $this->builder = null;

        return $row;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function insertSkuRecord(array $record): bool
    {
        $this->builder = null;

        return $this->insert($record) !== false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function paginateSearch(
        ?string $term,
        ?string $sheetName,
        int $perPage = 100,
        string $group = 'bins',
        ?string $net32Filter = null,
        ?string $quantityFilter = null,
        ?string $shipStationFilter = null,
    ): array {
        if ($term !== null && $term !== '') {
            return $this->paginateGroupedSearch($term, $sheetName, $perPage, $group, $net32Filter, $quantityFilter, $shipStationFilter);
        }

        $this->builder = null;
        $this->applyFilters($term, $sheetName, $net32Filter, $quantityFilter, $shipStationFilter);

        return $this->applyListOrdering()
            ->paginate($perPage, $group);
    }

    /**
     * When searching, paginate by rack/bin group and include all SKUs in each group.
     *
     * @return list<array<string, mixed>>
     */
    private function paginateGroupedSearch(
        string $term,
        ?string $sheetName,
        int $perPage,
        string $group,
        ?string $net32Filter,
        ?string $quantityFilter = null,
        ?string $shipStationFilter = null,
    ): array {
        $page   = max(1, (int) (service('pager')->getCurrentPage($group) ?: 1));
        $total  = $this->countMatchingGroups($term, $sheetName, $net32Filter, $quantityFilter, $shipStationFilter);
        $groups = $this->getMatchingGroupKeysForPage($term, $sheetName, $net32Filter, $perPage, $page, $quantityFilter, $shipStationFilter);

        $this->builder = null;

        if ($groups === []) {
            $this->pager = service('pager');
            $this->pager->store($group, $page, $perPage, $total);

            return [];
        }

        $this->groupStart();

        foreach ($groups as $index => $groupRow) {
            if ($index === 0) {
                $this->groupStart();
            } else {
                $this->orGroupStart();
            }

            $this->where('sheet_name', $groupRow['sheet_name'])
                ->where('rack', $groupRow['rack'])
                ->where('bin', $groupRow['bin'])
                ->groupEnd();
        }

        $this->groupEnd();

        $results = $this->applyListOrdering()->findAll();

        $this->pager = service('pager');
        $this->pager->store($group, $page, $perPage, $total);

        return $results;
    }

    private function countMatchingGroups(
        ?string $term,
        ?string $sheetName,
        ?string $net32Filter,
        ?string $quantityFilter = null,
        ?string $shipStationFilter = null,
    ): int {
        $builder = $this->buildFilteredQuery($term, $sheetName, $net32Filter, $quantityFilter, $shipStationFilter);

        return $builder
            ->select('sheet_name, rack, bin')
            ->groupBy(['sheet_name', 'rack', 'bin'])
            ->countAllResults();
    }

    /**
     * @return list<array{sheet_name: string, rack: string, bin: string}>
     */
    private function getMatchingGroupKeysForPage(
        ?string $term,
        ?string $sheetName,
        ?string $net32Filter,
        int $perPage,
        int $page,
        ?string $quantityFilter = null,
        ?string $shipStationFilter = null,
    ): array {
        $offset = ($page - 1) * $perPage;

        $rows = $this->buildFilteredQuery($term, $sheetName, $net32Filter, $quantityFilter, $shipStationFilter)
            ->select('sheet_name, rack, bin')
            ->groupBy(['sheet_name', 'rack', 'bin'])
            ->orderBy('sheet_name', 'ASC')
            ->orderBy('rack', 'ASC')
            ->orderBy('bin', 'ASC')
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        return array_values(array_map(static fn (array $row): array => [
            'sheet_name' => (string) $row['sheet_name'],
            'rack'       => (string) $row['rack'],
            'bin'        => (string) $row['bin'],
        ], $rows));
    }

    /**
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function buildFilteredQuery(
        ?string $term,
        ?string $sheetName,
        ?string $net32Filter,
        ?string $quantityFilter = null,
        ?string $shipStationFilter = null,
    ) {
        $builder = $this->db->table($this->table);

        if ($sheetName !== null && $sheetName !== '') {
            $builder->where('sheet_name', $sheetName);
        }

        if ($net32Filter === 'missing') {
            $builder->groupStart()
                ->where('net32_exists_lv', 0)
                ->orWhere('net32_exists_brook', 0)
                ->orWhere('net32_exists_coral', 0)
                ->groupEnd();
        } elseif ($net32Filter === 'ok') {
            $builder->groupStart()
                ->where('sku_net32_exists', 1)
                ->where('net32_checked_at IS NOT NULL', null, false)
                ->groupEnd();
        } elseif ($net32Filter === 'unchecked') {
            $builder->where('net32_checked_at', null);
        }

        if ($shipStationFilter === 'missing') {
            $builder->where('shipstation_exists', 0);
        } elseif ($shipStationFilter === 'unchecked') {
            $builder->where('shipstation_exists', null);
        } else {
            $this->applyShipStationStatusFilter($builder, $shipStationFilter);
        }

        if ($quantityFilter === 'zero') {
            $builder->where('quantity', 0);
        } elseif ($quantityFilter === 'low') {
            $this->applyLowStockFilterToBuilder($builder, service('appSettings')->lowStockThreshold());
        }

        if ($term !== null && $term !== '') {
            $builder->groupStart()
                ->like('sku', $term)
                ->orLike('name', $term)
                ->orLike('description', $term)
                ->orLike('rack', $term)
                ->orLike('bin', $term)
                ->orLike('sheet_name', $term)
                ->groupEnd();
        }

        return $builder;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($id): int => (int) $id, $ids))));

        if ($ids === []) {
            return [];
        }

        $this->builder = null;
        $rows = $this->whereIn('id', $ids)->findAll();
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[(int) $row['id']] = $row;
        }

        $ordered = [];

        foreach ($ids as $id) {
            if (isset($indexed[$id])) {
                $ordered[] = $indexed[$id];
            }
        }

        return $ordered;
    }

    private function applyListOrdering(): self
    {
        return $this->orderBy('sheet_name', 'ASC')
            ->orderBy('rack', 'ASC')
            ->orderBy('bin', 'ASC')
            ->orderBy('is_main_sku', 'DESC')
            ->orderBy('sku', 'ASC');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findBySheetName(string $sheetName): array
    {
        $sheetName = trim($sheetName);

        if ($sheetName === '') {
            return [];
        }

        $this->builder = null;

        return $this->where('LOWER(sheet_name)', strtolower($sheetName))
            ->orderBy('rack', 'ASC')
            ->orderBy('bin', 'ASC')
            ->orderBy('is_main_sku', 'DESC')
            ->orderBy('sku', 'ASC')
            ->findAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllForQuantitySync(): array
    {
        $this->builder = null;

        return $this->orderBy('sheet_name', 'ASC')
            ->orderBy('rack', 'ASC')
            ->orderBy('bin', 'ASC')
            ->orderBy('is_main_sku', 'DESC')
            ->orderBy('sku', 'ASC')
            ->findAll();
    }

    /**
     * @return list<string>
     */
    public function getSheetNames(): array
    {
        $rows = $this->db->table($this->table)
            ->select('sheet_name')
            ->distinct()
            ->orderBy('sheet_name', 'ASC')
            ->get()
            ->getResultArray();

        return array_values(array_filter(array_map(
            static fn (array $row): string => (string) $row['sheet_name'],
            $rows,
        )));
    }

    public function getLastSyncedAt(): ?string
    {
        $row = $this->db->table($this->table)
            ->selectMax('synced_at', 'last_synced')
            ->get()
            ->getRowArray();

        return isset($row['last_synced']) ? (string) $row['last_synced'] : null;
    }

    public function getLastNet32CheckedAt(): ?string
    {
        $row = $this->db->table($this->table)
            ->selectMax('net32_checked_at', 'last_checked')
            ->get()
            ->getRowArray();

        return isset($row['last_checked']) ? (string) $row['last_checked'] : null;
    }

    public function getLastShipStationCheckedAt(): ?string
    {
        $row = $this->db->table($this->table)
            ->selectMax('shipstation_checked_at', 'last_checked')
            ->get()
            ->getRowArray();

        return isset($row['last_checked']) ? (string) $row['last_checked'] : null;
    }

    /**
     * @return array{checked: int, missing: int, unchecked: int}
     */
    public function getNet32Stats(): array
    {
        $total = $this->countAllResults();

        $checked = $this->where('net32_checked_at IS NOT NULL', null, false)->countAllResults();
        $this->builder = null;

        // Same rule as inventory filter "Missing in any store".
        $missing = $this->groupStart()
            ->where('net32_exists_lv', 0)
            ->orWhere('net32_exists_brook', 0)
            ->orWhere('net32_exists_coral', 0)
            ->groupEnd()
            ->countAllResults();
        $this->builder = null;

        return [
            'checked'   => $checked,
            'missing'   => $missing,
            'unchecked' => max(0, $total - $checked),
        ];
    }

    public function countZeroQuantity(): int
    {
        $this->builder = null;

        return $this->where('quantity', 0)->countAllResults();
    }

    public function countLowStock(?int $threshold = null): int
    {
        $threshold ??= service('appSettings')->lowStockThreshold();
        $this->builder = null;
        $this->applyLowStockFilterToModel($threshold);

        $count = $this->countAllResults();
        $this->builder = null;

        return $count;
    }

    /**
     * Low stock = any store qty at or below threshold (null store qty ignored).
     *
     * @param \CodeIgniter\Database\BaseBuilder $builder
     */
    private function applyLowStockFilterToBuilder($builder, int $threshold): void
    {
        $builder->groupStart()
            ->groupStart()
                ->where('qty_lv IS NOT NULL', null, false)
                ->where('qty_lv <=', $threshold)
            ->groupEnd()
            ->orGroupStart()
                ->where('qty_brook IS NOT NULL', null, false)
                ->where('qty_brook <=', $threshold)
            ->groupEnd()
            ->orGroupStart()
                ->where('qty_coral IS NOT NULL', null, false)
                ->where('qty_coral <=', $threshold)
            ->groupEnd()
        ->groupEnd();
    }

    private function applyLowStockFilterToModel(int $threshold): void
    {
        $this->groupStart()
            ->groupStart()
                ->where('qty_lv IS NOT NULL', null, false)
                ->where('qty_lv <=', $threshold)
            ->groupEnd()
            ->orGroupStart()
                ->where('qty_brook IS NOT NULL', null, false)
                ->where('qty_brook <=', $threshold)
            ->groupEnd()
            ->orGroupStart()
                ->where('qty_coral IS NOT NULL', null, false)
                ->where('qty_coral <=', $threshold)
            ->groupEnd()
        ->groupEnd();
    }

    public function findByPosition(string $sheetName, string $rack, string $bin, ?string $sku = null, ?int $excludeId = null): ?array
    {
        $this->builder = null;

        $builder = $this->where('LOWER(sheet_name)', strtolower(trim($sheetName)))
            ->where('LOWER(rack)', strtolower(trim($rack)))
            ->where('LOWER(bin)', strtolower(trim($bin)));

        if ($sku !== null) {
            $builder->where('LOWER(sku)', strtolower(trim($sku)));
        }

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        $row = $builder->first();
        $this->builder = null;

        return $row;
    }

    public function findMainSkuInBin(string $sheetName, string $rack, string $bin, ?int $excludeId = null): ?array
    {
        $this->builder = null;

        $builder = $this->where('LOWER(sheet_name)', strtolower(trim($sheetName)))
            ->where('LOWER(rack)', strtolower(trim($rack)))
            ->where('LOWER(bin)', strtolower(trim($bin)))
            ->where('is_main_sku', 1);

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        $row = $builder->first();
        $this->builder = null;

        return $row;
    }

    /**
     * Validate manual add/edit input and return non-blocking warnings for the form modal.
     *
     * @param array<string, mixed> $input
     *
     * @return array{
     *     ok: bool,
     *     message?: string,
     *     warnings: list<string>,
     *     net32?: array{exists: bool|null, warning: string|null}
     * }
     */
    public function previewManualSave(array $input, ?int $id = null): array
    {
        $sheetName = trim((string) ($input['sheet_name'] ?? ''));
        $rack      = trim((string) ($input['rack'] ?? ''));
        $bin       = trim((string) ($input['bin'] ?? ''));
        $sku       = trim((string) ($input['sku'] ?? ''));

        if ($sheetName === '' || $rack === '' || $bin === '' || $sku === '') {
            return [
                'ok'       => false,
                'message'  => 'Sheet, Rack, Bin, and SKU are required.',
                'warnings' => [],
            ];
        }

        $duplicateSku = $this->findBySku($sku, $id);

        if ($duplicateSku !== null) {
            return [
                'ok'       => false,
                'message'  => $this->buildDuplicateSkuMessage($duplicateSku),
                'warnings' => [],
            ];
        }

        if ($this->findByPosition($sheetName, $rack, $bin, $sku, $id) !== null) {
            return [
                'ok'       => false,
                'message'  => $this->buildDuplicateSkuMessage([
                    'sku'        => $sku,
                    'sheet_name' => $sheetName,
                    'rack'       => $rack,
                    'bin'        => $bin,
                ]),
                'warnings' => [],
            ];
        }

        $isMainSku = ! empty($input['is_main_sku']);
        $mainSkuAtBin = $this->findMainSkuInBin($sheetName, $rack, $bin, $id);

        if ($id === null) {
            if ($isMainSku && $mainSkuAtBin !== null) {
                return [
                    'ok'       => false,
                    'message'  => sprintf(
                        'A main SKU already exists at Sheet %s, Rack %s, Bin %s (SKU %s).',
                        (string) ($mainSkuAtBin['sheet_name'] ?? $sheetName),
                        (string) ($mainSkuAtBin['rack'] ?? $rack),
                        (string) ($mainSkuAtBin['bin'] ?? $bin),
                        (string) ($mainSkuAtBin['sku'] ?? ''),
                    ),
                    'warnings' => [],
                ];
            }

            if (! $isMainSku && $mainSkuAtBin === null) {
                return [
                    'ok'       => false,
                    'message'  => 'Add a main SKU for this Sheet, Rack, and Bin before adding an alternate SKU.',
                    'warnings' => [],
                ];
            }
        }

        $existingRow = $id !== null ? $this->find($id) : null;
        $validation  = $this->validateManualSave($sheetName, $sku, $existingRow);

        return [
            'ok'       => true,
            'warnings' => $validation['warnings'],
            'net32'    => $validation['net32'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     ok: bool,
     *     message: string,
     *     id?: int,
     *     warnings?: list<string>,
     *     needs_confirm?: bool
     * }
     */
    public function saveFromInput(array $input, ?int $id = null): array
    {
        $sheetName = trim((string) ($input['sheet_name'] ?? ''));
        $rack      = trim((string) ($input['rack'] ?? ''));
        $bin       = trim((string) ($input['bin'] ?? ''));
        $sku       = trim((string) ($input['sku'] ?? ''));
        $name      = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $quantityRaw = $input['quantity'] ?? null;
        $quantityProvided = is_string($quantityRaw)
            ? trim($quantityRaw) !== ''
            : ($quantityRaw !== null && $quantityRaw !== '');
        $quantity = $quantityProvided ? max(0, (int) $quantityRaw) : 0;
        $storeQuantities = $this->resolveStoreQuantitiesFromInput($input, $quantityProvided, $quantity);
        $confirmWarnings = ! empty($input['confirm_warnings']);

        $preview = $this->previewManualSave($input, $id);

        if (! $preview['ok']) {
            return ['ok' => false, 'message' => $preview['message'] ?? 'Validation failed.'];
        }

        $warnings = $preview['warnings'];

        if ($warnings !== [] && ! $confirmWarnings) {
            return [
                'ok'            => true,
                'needs_confirm' => true,
                'warnings'      => $warnings,
                'message'       => 'Please confirm the warnings before saving.',
            ];
        }

        $net32Inspection = $preview['net32'] ?? ['exists' => null, 'warning' => null];

        $record = [
            'sheet_name'  => $sheetName,
            'rack'        => $rack,
            'bin'         => $bin,
            'sku'         => $sku,
            'is_main_sku' => ! empty($input['is_main_sku']) ? 1 : 0,
            'name'        => $name !== '' ? $name : null,
            'description' => $description !== '' ? $description : null,
        ];

        if ($id === null) {
            $initial = $storeQuantities ?? [
                Net32Stores::LV    => 0,
                Net32Stores::BROOK => 0,
                Net32Stores::CORAL => 0,
            ];
            $record['quantity']  = $initial[Net32Stores::LV] ?? 0;
            $record['qty_lv']    = $initial[Net32Stores::LV] ?? 0;
            $record['qty_brook'] = $initial[Net32Stores::BROOK] ?? 0;
            $record['qty_coral'] = $initial[Net32Stores::CORAL] ?? 0;
        }

        $this->applyNet32InspectionToRecord($record, $net32Inspection);

        if ($id === null) {
            $this->builder = null;

            if ($this->insert($record) === false) {
                $duplicateSku = $this->findBySku($sku);

                if ($duplicateSku !== null) {
                    return ['ok' => false, 'message' => $this->buildDuplicateSkuMessage($duplicateSku)];
                }

                return ['ok' => false, 'message' => 'Could not save inventory row.'];
            }

            service('googleSheets')->rememberSheetName($sheetName);

            return $this->withWarnings([
                'ok'      => true,
                'message' => 'Inventory row added.',
                'id'      => (int) $this->getInsertID(),
            ], $warnings);
        }

        $existing = $this->find($id);

        if ($existing === null) {
            return ['ok' => false, 'message' => 'Inventory row not found.'];
        }

        if (strcasecmp((string) ($existing['sku'] ?? ''), $sku) !== 0) {
            $record['sku_net32_exists']    = null;
            $record['net32_exists_lv']     = null;
            $record['net32_exists_brook']  = null;
            $record['net32_exists_coral']  = null;
            $record['net32_checked_at']    = null;
            $this->applyNet32InspectionToRecord($record, $net32Inspection);
        }

        $activityLog = service('activityLog');
        $pushQuantities = [];

        if ($storeQuantities !== null) {
            if ($quantityProvided) {
                $pushQuantities = $storeQuantities;
            } else {
                foreach ($storeQuantities as $store => $storeQty) {
                    $column = Net32Stores::qtyColumn((string) $store);
                    $previous = $existing[$column] ?? (
                        $store === Net32Stores::LV ? ($existing['quantity'] ?? null) : null
                    );

                    if ($previous === null || $previous === '' || (int) $previous !== (int) $storeQty) {
                        $pushQuantities[$store] = $storeQty;
                    }
                }
            }
        }

        $quantityChanged = $pushQuantities !== [];
        $isMainSkuUpdate = ! empty($existing['is_main_sku']);
        $alternateRows   = $isMainSkuUpdate
            ? $this->findAlternateSkusInBin($sheetName, $rack, $bin)
            : [];
        $quantitySyncRows = $quantityChanged
            ? $this->buildQuantitySyncRows($existing, $alternateRows, $isMainSkuUpdate)
            : [];
        $syncedAt = date('Y-m-d H:i:s');
        $syncedToNet32 = false;
        $alternateApplied = [];

        if ($quantityChanged) {
            $quantityCheck = service('inventoryQuantityCheck');

            foreach ($quantitySyncRows as $syncRow) {
                $syncSku = trim((string) ($syncRow['sku'] ?? ''));

                if ($syncSku === '') {
                    continue;
                }

                $net32Result = $quantityCheck->pushStoreQuantitiesToNet32($syncSku, $pushQuantities);

                foreach ($net32Result['warnings'] ?? [] as $warning) {
                    $warnings[] = $warning;
                }

                if (! $net32Result['ok']) {
                    $failedChanges = $activityLog->detectInventoryChanges($existing, $record);
                    $activityLog->logInventoryEdit(
                        $existing,
                        $record,
                        $id,
                        $failedChanges,
                        'failed',
                        $net32Result['message'],
                    );

                    return ['ok' => false, 'message' => $net32Result['message']];
                }

                $syncedToNet32 = true;
                $appliedRecord = $quantityCheck->recordFromAppliedSplit($net32Result['applied'] ?? []);

                // Keep local values for stores that were requested even if Net32 skipped them.
                foreach ($pushQuantities as $store => $storeQty) {
                    $appliedRecord[Net32Stores::qtyColumn((string) $store)] = $storeQty;
                    if ($store === Net32Stores::LV) {
                        $appliedRecord['quantity'] = $storeQty;
                    }
                }

                if (strcasecmp($syncSku, $sku) === 0) {
                    $record = array_merge($record, $appliedRecord);
                } else {
                    $alternateApplied[(int) ($syncRow['id'] ?? 0)] = $appliedRecord;
                }
            }

            $this->applyNet32InspectionToRecord($record, $net32Inspection, $syncedAt);
        } elseif (! isset($record['sku_net32_exists'])) {
            $this->applyNet32InspectionToRecord($record, $net32Inspection, $syncedAt);
        }

        $changes = $activityLog->detectInventoryChanges($existing, $record);

        $this->builder = null;

        if (! $this->update($id, $record)) {
            return ['ok' => false, 'message' => 'Could not update inventory row.'];
        }

        if ($changes !== []) {
            $activityLog->logInventoryEdit($existing, $record, $id, $changes);
        }

        $updatedSkus = [$sku];

        if ($quantityChanged && $alternateRows !== []) {
            foreach ($alternateRows as $alternate) {
                $alternateId = (int) $alternate['id'];
                $alternateSku = trim((string) ($alternate['sku'] ?? ''));

                if ($alternateSku === '') {
                    continue;
                }

                $alternateInspection = $this->resolveNet32InspectionForRow($alternate, $alternateSku);
                $fallback = [];

                foreach ($pushQuantities as $store => $storeQty) {
                    $fallback[Net32Stores::qtyColumn((string) $store)] = $storeQty;
                    if ($store === Net32Stores::LV) {
                        $fallback['quantity'] = $storeQty;
                    }
                }

                $alternateRecord = array_merge(
                    $alternateApplied[$alternateId] ?? $fallback,
                    ['net32_checked_at' => $syncedAt],
                );

                if ($alternateInspection['exists'] === true) {
                    $alternateRecord['sku_net32_exists'] = 1;
                } elseif ($alternateInspection['exists'] === false) {
                    $alternateRecord['sku_net32_exists'] = 0;

                    if ($alternateInspection['warning'] !== null) {
                        $warnings[] = $alternateInspection['warning'];
                    }
                }

                $alternateChanges = $activityLog->detectInventoryChanges(
                    $alternate,
                    array_merge($alternate, $alternateRecord),
                );

                if ($alternateChanges === []) {
                    continue;
                }

                $this->builder = null;

                if (! $this->update($alternateId, $alternateRecord)) {
                    return ['ok' => false, 'message' => 'Could not update alternate SKU quantity.'];
                }

                $activityLog->logInventoryEdit(
                    $alternate,
                    array_merge($alternate, $alternateRecord),
                    $alternateId,
                    $alternateChanges,
                );

                $updatedSkus[] = $alternateSku;
            }
        }

        return $this->withWarnings([
            'ok'      => true,
            'message' => $quantityChanged
                ? $this->buildQuantityUpdatedMessage(
                    $quantityProvided ? $quantity : array_sum($pushQuantities),
                    $updatedSkus,
                    $syncedToNet32,
                    $pushQuantities,
                )
                : 'Inventory row updated.',
            'id'      => $id,
        ], $warnings);
    }

    /**
     * Inline qty edit from Net32 chips: one store and/or a total split.
     *
     * @param array<string, mixed> $input
     *
     * @return array{
     *     ok: bool,
     *     message: string,
     *     quantity?: int|null,
     *     qty_lv?: int|null,
     *     qty_brook?: int|null,
     *     qty_coral?: int|null,
     *     sku_net32_exists?: bool|null,
     *     net32_exists_lv?: bool|null,
     *     net32_exists_brook?: bool|null,
     *     net32_exists_coral?: bool|null
     * }
     */
    public function updateQuantitiesInline(int $id, array $input): array
    {
        $existing = $this->find($id);

        if ($existing === null) {
            return ['ok' => false, 'message' => 'Inventory row not found.'];
        }

        $sku = trim((string) ($existing['sku'] ?? ''));

        if ($sku === '') {
            return ['ok' => false, 'message' => 'This row has no SKU to sync to Net32.'];
        }

        $quantityRaw = $input['quantity'] ?? null;
        $quantityProvided = is_string($quantityRaw)
            ? trim($quantityRaw) !== ''
            : ($quantityRaw !== null && $quantityRaw !== '');
        $quantity = $quantityProvided ? max(0, (int) $quantityRaw) : 0;

        // Allow store=lv&qty=… as a shorthand from the chip editor.
        $storeKey = strtolower(trim((string) ($input['store'] ?? '')));
        $qtyRaw   = $input['qty'] ?? null;
        $qtyProvided = is_string($qtyRaw)
            ? trim($qtyRaw) !== ''
            : ($qtyRaw !== null && $qtyRaw !== '');

        if ($qtyProvided && in_array($storeKey, Net32Stores::ids(), true)) {
            $input[Net32Stores::qtyColumn($storeKey)] = max(0, (int) $qtyRaw);
        }

        $pushQuantities = $this->resolveStoreQuantitiesFromInput($input, $quantityProvided, $quantity);

        if ($pushQuantities === null || $pushQuantities === []) {
            return ['ok' => false, 'message' => 'Enter a quantity to update.'];
        }

        $isMainSkuUpdate = ! empty($existing['is_main_sku']);
        $alternateRows   = $isMainSkuUpdate
            ? $this->findAlternateSkusInBin(
                (string) ($existing['sheet_name'] ?? ''),
                (string) ($existing['rack'] ?? ''),
                (string) ($existing['bin'] ?? ''),
            )
            : [];
        $quantitySyncRows = $this->buildQuantitySyncRows($existing, $alternateRows, $isMainSkuUpdate);
        $activityLog      = service('activityLog');
        $quantityCheck    = service('inventoryQuantityCheck');
        $warnings         = [];
        $syncedAt         = date('Y-m-d H:i:s');
        $alternateApplied = [];
        $record           = [];

        foreach ($quantitySyncRows as $syncRow) {
            $syncSku = trim((string) ($syncRow['sku'] ?? ''));

            if ($syncSku === '') {
                continue;
            }

            $net32Result = $quantityCheck->pushStoreQuantitiesToNet32($syncSku, $pushQuantities);

            foreach ($net32Result['warnings'] ?? [] as $warning) {
                $warnings[] = $warning;
            }

            if (! $net32Result['ok']) {
                $failedRecord = [];

                foreach ($pushQuantities as $store => $storeQty) {
                    $failedRecord[Net32Stores::qtyColumn((string) $store)] = $storeQty;
                    if ($store === Net32Stores::LV) {
                        $failedRecord['quantity'] = $storeQty;
                    }
                }

                $activityLog->logInventoryEdit(
                    $existing,
                    array_merge($existing, $failedRecord),
                    $id,
                    $activityLog->detectInventoryChanges($existing, array_merge($existing, $failedRecord)),
                    'failed',
                    $net32Result['message'],
                );

                return ['ok' => false, 'message' => $net32Result['message']];
            }

            $appliedRecord = $quantityCheck->recordFromAppliedSplit($net32Result['applied'] ?? []);

            foreach ($pushQuantities as $store => $storeQty) {
                $appliedRecord[Net32Stores::qtyColumn((string) $store)] = $storeQty;
                if ($store === Net32Stores::LV) {
                    $appliedRecord['quantity'] = $storeQty;
                }
            }

            $appliedRecord['net32_checked_at'] = $syncedAt;

            if ((int) ($syncRow['id'] ?? 0) === $id) {
                $record = $appliedRecord;
            } else {
                $alternateApplied[(int) ($syncRow['id'] ?? 0)] = $appliedRecord;
            }
        }

        if ($record === []) {
            return ['ok' => false, 'message' => 'Could not update inventory quantity.'];
        }

        $changes = $activityLog->detectInventoryChanges($existing, array_merge($existing, $record));

        $this->builder = null;

        if (! $this->update($id, $record)) {
            return ['ok' => false, 'message' => 'Could not update inventory row.'];
        }

        if ($changes !== []) {
            $activityLog->logInventoryEdit($existing, array_merge($existing, $record), $id, $changes);
        }

        $updatedSkus = [$sku];

        if ($alternateRows !== []) {
            foreach ($alternateRows as $alternate) {
                $alternateId = (int) ($alternate['id'] ?? 0);
                $alternateSku = trim((string) ($alternate['sku'] ?? ''));

                if ($alternateId <= 0 || $alternateSku === '') {
                    continue;
                }

                $alternateRecord = $alternateApplied[$alternateId] ?? null;

                if ($alternateRecord === null) {
                    continue;
                }

                $alternateChanges = $activityLog->detectInventoryChanges(
                    $alternate,
                    array_merge($alternate, $alternateRecord),
                );

                if ($alternateChanges === []) {
                    continue;
                }

                $this->builder = null;

                if (! $this->update($alternateId, $alternateRecord)) {
                    return ['ok' => false, 'message' => 'Could not update alternate SKU quantity.'];
                }

                $activityLog->logInventoryEdit(
                    $alternate,
                    array_merge($alternate, $alternateRecord),
                    $alternateId,
                    $alternateChanges,
                );

                $updatedSkus[] = $alternateSku;
            }
        }

        $fresh = $this->find($id) ?? array_merge($existing, $record);

        return $this->withWarnings([
            'ok'                 => true,
            'message'            => $this->buildQuantityUpdatedMessage(
                $quantityProvided ? $quantity : array_sum($pushQuantities),
                $updatedSkus,
                true,
                $pushQuantities,
            ),
            'id'                 => $id,
            'quantity'           => isset($fresh['quantity']) ? (int) $fresh['quantity'] : null,
            'qty_lv'             => $this->nullableStoreQty($fresh, Net32Stores::LV),
            'qty_brook'          => $this->nullableStoreQty($fresh, Net32Stores::BROOK),
            'qty_coral'          => $this->nullableStoreQty($fresh, Net32Stores::CORAL),
            'sku_net32_exists'   => Net32Stores::existsFromRow($fresh, Net32Stores::LV),
            'net32_exists_lv'    => Net32Stores::existsFromRow($fresh, Net32Stores::LV),
            'net32_exists_brook' => Net32Stores::existsFromRow($fresh, Net32Stores::BROOK),
            'net32_exists_coral' => Net32Stores::existsFromRow($fresh, Net32Stores::CORAL),
        ], $warnings);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAlternateSkusInBin(string $sheetName, string $rack, string $bin): array
    {
        $this->builder = null;

        return $this->where([
            'sheet_name'  => $sheetName,
            'rack'        => $rack,
            'bin'         => $bin,
            'is_main_sku' => 0,
        ])
            ->orderBy('sku', 'ASC')
            ->findAll();
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{ok: bool, message?: string}
     */
    public function canManuallyDeleteRow(array $row): array
    {
        if (empty($row['is_main_sku'])) {
            return ['ok' => true];
        }

        $alternates = $this->findAlternateSkusInBin(
            (string) ($row['sheet_name'] ?? ''),
            (string) ($row['rack'] ?? ''),
            (string) ($row['bin'] ?? ''),
        );

        if ($alternates === []) {
            return ['ok' => true];
        }

        $count = count($alternates);

        return [
            'ok'      => false,
            'message' => sprintf(
                'Remove %d alternate SKU%s before deleting main SKU %s.',
                $count,
                $count === 1 ? '' : 's',
                (string) ($row['sku'] ?? ''),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $mainRow
     * @param list<array<string, mixed>> $alternateRows
     *
     * @return list<array<string, mixed>>
     */
    private function buildQuantitySyncRows(array $mainRow, array $alternateRows, bool $includeAlternates): array
    {
        $rows = [$mainRow];

        if ($includeAlternates) {
            foreach ($alternateRows as $alternate) {
                $rows[] = $alternate;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, int>|null
     */
    private function resolveStoreQuantitiesFromInput(array $input, bool $quantityProvided, int $quantity): ?array
    {
        if ($quantityProvided) {
            return Net32Stores::splitQuantity($quantity);
        }

        $fieldMap = [
            Net32Stores::LV    => 'qty_lv',
            Net32Stores::BROOK => 'qty_brook',
            Net32Stores::CORAL => 'qty_coral',
        ];
        $resolved = [];

        foreach ($fieldMap as $store => $field) {
            $raw = $input[$field] ?? null;
            $provided = is_string($raw)
                ? trim($raw) !== ''
                : ($raw !== null && $raw !== '');

            if (! $provided) {
                continue;
            }

            $resolved[$store] = max(0, (int) $raw);
        }

        return $resolved === [] ? null : $resolved;
    }

    /**
     * @param list<string> $skus
     * @param array{lv?: int, brook?: int, coral?: int} $split
     */
    private function buildQuantityUpdatedMessage(
        int $quantity,
        array $skus,
        bool $syncedToNet32 = true,
        array $split = [],
    ): string {
        $skus = array_values(array_unique(array_filter(array_map(
            static fn (string $sku): string => trim($sku),
            $skus,
        ))));
        $splitLabel = $split !== []
            ? Net32Stores::formatSplit($split)
            : Net32Stores::formatSplit(Net32Stores::splitQuantity($quantity));

        if ($skus === []) {
            return $syncedToNet32
                ? 'Inventory row updated and quantity synced to Net32 (' . $splitLabel . ').'
                : 'Inventory row updated.';
        }

        $base = sprintf(
            'Quantity %s split to %s for %s: %s.',
            number_format($quantity),
            $splitLabel,
            count($skus) === 1 ? 'SKU' : 'SKUs',
            implode(', ', $skus),
        );

        return $syncedToNet32 ? $base . ' Synced to Net32.' : $base;
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function buildDuplicateSkuMessage(array $existing): string
    {
        return sprintf(
            'SKU "%s" already exists at Sheet %s, Rack %s, Bin %s.',
            (string) ($existing['sku'] ?? ''),
            (string) ($existing['sheet_name'] ?? ''),
            (string) ($existing['rack'] ?? ''),
            (string) ($existing['bin'] ?? ''),
        );
    }

    /**
     * @return array{
     *     warnings: list<string>,
     *     net32: array{exists: bool|null, warning: string|null}
     * }
     */
    /**
     * @param array<string, mixed>|null $existingRow
     *
     * @return array{
     *     warnings: list<string>,
     *     net32: array{exists: bool|null, warning: string|null}
     * }
     */
    private function validateManualSave(string $sheetName, string $sku, ?array $existingRow = null): array
    {
        $warnings = [];

        if (! service('googleSheets')->sheetExists($sheetName)) {
            $warnings[] = sprintf('Warning: Sheet "%s" was not found in Google Sheets.', $sheetName);
        }

        $net32 = $this->resolveNet32InspectionForRow($existingRow ?? [], $sku);

        if ($net32['warning'] !== null) {
            $warnings[] = $net32['warning'];
        }

        return [
            'warnings' => $warnings,
            'net32'    => $net32,
        ];
    }

    /**
     * Reuse a recent Net32 status from the DB when the SKU has not changed.
     *
     * @param array<string, mixed> $row
     *
     * @return array{exists: bool|null, warning: string|null}
     */
    private function resolveNet32InspectionForRow(array $row, string $sku): array
    {
        $sku = trim($sku);

        if ($sku === '') {
            return ['exists' => null, 'warning' => null];
        }

        $rowSku = trim((string) ($row['sku'] ?? ''));

        if (
            $rowSku !== ''
            && strcasecmp($rowSku, $sku) === 0
            && ($row['net32_checked_at'] ?? null) !== null
            && array_key_exists('sku_net32_exists', $row)
            && $row['sku_net32_exists'] !== null
        ) {
            $exists = (bool) (int) $row['sku_net32_exists'];

            return [
                'exists'  => $exists,
                'warning' => $exists ? null : sprintf('Warning: SKU %s was not found in Net32.', $sku),
            ];
        }

        return service('inventoryQuantityCheck')->inspectSkuInNet32($sku);
    }

    /**
     * @param array{exists: bool|null, warning: string|null} $inspection
     * @param array<string, mixed> $record
     */
    private function applyNet32InspectionToRecord(array &$record, array $inspection, ?string $checkedAt = null): void
    {
        if ($inspection['exists'] === null) {
            return;
        }

        $record['sku_net32_exists'] = $inspection['exists'] ? 1 : 0;
        $record['net32_exists_lv']  = $inspection['exists'] ? 1 : 0;
        $record['net32_checked_at'] = $checkedAt ?? date('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableStoreQty(array $row, string $store): ?int
    {
        $value = Net32Stores::qtyFromRow($row, $store);

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array{ok: bool, message: string, id?: int} $result
     * @param list<string> $warnings
     *
     * @return array{ok: bool, message: string, id?: int, warnings?: list<string>}
     */
    private function withWarnings(array $result, array $warnings): array
    {
        $warnings = array_values(array_unique(array_filter($warnings)));

        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }

        return $result;
    }

    /**
     * @param \CodeIgniter\Database\BaseBuilder $builder
     */
    private function applyShipStationStatusFilter($builder, ?string $shipStationFilter): void
    {
        if ($shipStationFilter === 'ok') {
            $builder->groupStart()
                ->where('shipstation_exists', 1)
                ->where('shipstation_checked_at IS NOT NULL', null, false)
                ->groupEnd();

            return;
        }

        if ($shipStationFilter === 'wrong_warehouse') {
            $configuredWarehouse = service('shipStationLocationCheck')->getConfiguredWarehouseNameForFilter();

            $builder->where('shipstation_exists', 1);

            if ($configuredWarehouse === null) {
                $builder->where('1 = 0', null, false);

                return;
            }

            $builder->groupStart()
                ->where('shipstation_warehouse IS NOT NULL', null, false)
                ->where('LOWER(shipstation_warehouse) !=', strtolower($configuredWarehouse))
                ->groupEnd();

            return;
        }

        if ($shipStationFilter === 'mismatch') {
            $configuredWarehouse = service('shipStationLocationCheck')->getConfiguredWarehouseNameForFilter();

            $builder->groupStart()
                ->where('shipstation_exists', 1)
                ->where('shipstation_location_matches', 0);

            if ($configuredWarehouse !== null) {
                $builder->where('LOWER(shipstation_warehouse)', strtolower($configuredWarehouse));
            }

            $builder->groupEnd();
        }
    }

    private function applyShipStationStatusFilterToModel(?string $shipStationFilter): void
    {
        if ($shipStationFilter === 'ok') {
            $this->groupStart()
                ->where('shipstation_exists', 1)
                ->where('shipstation_checked_at IS NOT NULL', null, false)
                ->groupEnd();

            return;
        }

        if ($shipStationFilter === 'wrong_warehouse') {
            $configuredWarehouse = service('shipStationLocationCheck')->getConfiguredWarehouseNameForFilter();

            $this->where('shipstation_exists', 1);

            if ($configuredWarehouse === null) {
                $this->where('1 = 0', null, false);

                return;
            }

            $this->groupStart()
                ->where('shipstation_warehouse IS NOT NULL', null, false)
                ->where('LOWER(shipstation_warehouse) !=', strtolower($configuredWarehouse))
                ->groupEnd();

            return;
        }

        if ($shipStationFilter === 'mismatch') {
            $configuredWarehouse = service('shipStationLocationCheck')->getConfiguredWarehouseNameForFilter();

            $this->groupStart()
                ->where('shipstation_exists', 1)
                ->where('shipstation_location_matches', 0);

            if ($configuredWarehouse !== null) {
                $this->where('LOWER(shipstation_warehouse)', strtolower($configuredWarehouse));
            }

            $this->groupEnd();
        }
    }

    private function applyFilters(
        ?string $term,
        ?string $sheetName,
        ?string $net32Filter = null,
        ?string $quantityFilter = null,
        ?string $shipStationFilter = null,
    ): void {
        if ($sheetName !== null && $sheetName !== '') {
            $this->where('sheet_name', $sheetName);
        }

        if ($net32Filter === 'missing') {
            $this->groupStart()
                ->where('net32_exists_lv', 0)
                ->orWhere('net32_exists_brook', 0)
                ->orWhere('net32_exists_coral', 0)
                ->groupEnd();
        } elseif ($net32Filter === 'ok') {
            $this->groupStart()
                ->where('sku_net32_exists', 1)
                ->where('net32_checked_at IS NOT NULL', null, false)
                ->groupEnd();
        } elseif ($net32Filter === 'unchecked') {
            $this->where('net32_checked_at', null);
        }

        if ($shipStationFilter === 'missing') {
            $this->where('shipstation_exists', 0);
        } elseif ($shipStationFilter === 'unchecked') {
            $this->where('shipstation_exists', null);
        } else {
            $this->applyShipStationStatusFilterToModel($shipStationFilter);
        }

        if ($quantityFilter === 'zero') {
            $this->where('quantity', 0);
        } elseif ($quantityFilter === 'low') {
            $this->applyLowStockFilterToModel(service('appSettings')->lowStockThreshold());
        }

        if ($term === null || $term === '') {
            return;
        }

        $this->groupStart()
            ->like('sku', $term)
            ->orLike('name', $term)
            ->orLike('description', $term)
            ->orLike('rack', $term)
            ->orLike('bin', $term)
            ->orLike('sheet_name', $term)
            ->groupEnd();
    }
}
