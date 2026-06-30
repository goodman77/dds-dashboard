<?php

declare(strict_types=1);

namespace App\Models;

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
        'sku_net32_exists',
        'net32_checked_at',
        'synced_at',
    ];
    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;
    protected $useTimestamps         = true;

    public function existsSku(string $sheetName, string $rack, string $bin, string $sku): bool
    {
        $this->builder = null;

        return $this->where([
            'sheet_name' => $sheetName,
            'rack'       => $rack,
            'bin'        => $bin,
            'sku'        => $sku,
        ])->first() !== null;
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
    ): array {
        if ($term !== null && $term !== '') {
            return $this->paginateGroupedSearch($term, $sheetName, $perPage, $group, $net32Filter, $quantityFilter);
        }

        $this->builder = null;
        $this->applyFilters($term, $sheetName, $net32Filter, $quantityFilter);

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
    ): array {
        $page   = max(1, (int) (service('pager')->getCurrentPage($group) ?: 1));
        $total  = $this->countMatchingGroups($term, $sheetName, $net32Filter, $quantityFilter);
        $groups = $this->getMatchingGroupKeysForPage($term, $sheetName, $net32Filter, $perPage, $page, $quantityFilter);

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
    ): int {
        $builder = $this->buildFilteredQuery($term, $sheetName, $net32Filter, $quantityFilter);

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
    ): array {
        $offset = ($page - 1) * $perPage;

        $rows = $this->buildFilteredQuery($term, $sheetName, $net32Filter, $quantityFilter)
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
    ) {
        $builder = $this->db->table($this->table);

        if ($sheetName !== null && $sheetName !== '') {
            $builder->where('sheet_name', $sheetName);
        }

        if ($net32Filter === 'missing') {
            $builder->where('sku_net32_exists', 0);
        } elseif ($net32Filter === 'ok') {
            $builder->groupStart()
                ->where('sku_net32_exists', 1)
                ->where('net32_checked_at IS NOT NULL', null, false)
                ->groupEnd();
        } elseif ($net32Filter === 'unchecked') {
            $builder->where('net32_checked_at', null);
        }

        if ($quantityFilter === 'zero') {
            $builder->where('quantity', 0);
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

    /**
     * @return array{checked: int, missing: int, unchecked: int}
     */
    public function getNet32Stats(): array
    {
        $total = $this->countAllResults();

        $checked = $this->where('net32_checked_at IS NOT NULL', null, false)->countAllResults();
        $this->builder = null;

        $missing = $this->where('sku_net32_exists', 0)->countAllResults();
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

    public function findByPosition(string $sheetName, string $rack, string $bin, ?string $sku = null, ?int $excludeId = null): ?array
    {
        $this->builder = null;

        $builder = $this->where('sheet_name', $sheetName)
            ->where('rack', $rack)
            ->where('bin', $bin);

        if ($sku !== null) {
            $builder->where('sku', $sku);
        }

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        $row = $builder->first();
        $this->builder = null;

        return $row;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, message: string, id?: int}
     */
    public function saveFromInput(array $input, ?int $id = null): array
    {
        $sheetName = trim((string) ($input['sheet_name'] ?? ''));
        $rack      = trim((string) ($input['rack'] ?? ''));
        $bin       = trim((string) ($input['bin'] ?? ''));
        $sku       = trim((string) ($input['sku'] ?? ''));
        $name      = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $quantity  = max(0, (int) ($input['quantity'] ?? 0));

        if ($sheetName === '' || $rack === '' || $bin === '' || $sku === '') {
            return ['ok' => false, 'message' => 'Sheet, Rack, Bin, and SKU are required.'];
        }

        if ($this->findByPosition($sheetName, $rack, $bin, $sku, $id) !== null) {
            return ['ok' => false, 'message' => 'An inventory row already exists for this Sheet, Rack, Bin, and SKU.'];
        }

        $record = [
            'sheet_name'  => $sheetName,
            'rack'        => $rack,
            'bin'         => $bin,
            'sku'         => $sku,
            'is_main_sku' => ! empty($input['is_main_sku']) ? 1 : 0,
            'name'        => $name !== '' ? $name : null,
            'description' => $description !== '' ? $description : null,
            'quantity'    => $quantity,
        ];

        if ($id === null) {
            $this->builder = null;

            if ($this->insert($record) === false) {
                return ['ok' => false, 'message' => 'Could not save inventory row.'];
            }

            return [
                'ok'      => true,
                'message' => 'Inventory row added.',
                'id'      => (int) $this->getInsertID(),
            ];
        }

        $existing = $this->find($id);

        if ($existing === null) {
            return ['ok' => false, 'message' => 'Inventory row not found.'];
        }

        if (strcasecmp((string) ($existing['sku'] ?? ''), $sku) !== 0) {
            $record['sku_net32_exists'] = null;
            $record['net32_checked_at'] = null;
        }

        $activityLog = service('activityLog');
        $changes     = $activityLog->detectInventoryChanges($existing, $record);
        $quantityChanged = isset($changes['quantity']);

        if ($quantityChanged) {
            $net32Result = service('inventoryQuantityCheck')->pushQuantityToNet32($sku, $quantity);

            if (! $net32Result['ok']) {
                $activityLog->logInventoryEdit(
                    $existing,
                    $record,
                    $id,
                    $changes,
                    'failed',
                    $net32Result['message'],
                );

                return ['ok' => false, 'message' => $net32Result['message']];
            }

            $record['sku_net32_exists'] = 1;
            $record['net32_checked_at'] = date('Y-m-d H:i:s');
        }

        $this->builder = null;

        if (! $this->update($id, $record)) {
            return ['ok' => false, 'message' => 'Could not update inventory row.'];
        }

        if ($changes !== []) {
            $activityLog->logInventoryEdit($existing, $record, $id, $changes);
        }

        return [
            'ok'      => true,
            'message' => $quantityChanged
                ? 'Inventory row updated and quantity synced to Net32.'
                : 'Inventory row updated.',
            'id'      => $id,
        ];
    }

    private function applyFilters(
        ?string $term,
        ?string $sheetName,
        ?string $net32Filter = null,
        ?string $quantityFilter = null,
    ): void {
        if ($sheetName !== null && $sheetName !== '') {
            $this->where('sheet_name', $sheetName);
        }

        if ($net32Filter === 'missing') {
            $this->where('sku_net32_exists', 0);
        } elseif ($net32Filter === 'ok') {
            $this->groupStart()
                ->where('sku_net32_exists', 1)
                ->where('net32_checked_at IS NOT NULL', null, false)
                ->groupEnd();
        } elseif ($net32Filter === 'unchecked') {
            $this->where('net32_checked_at', null);
        }

        if ($quantityFilter === 'zero') {
            $this->where('quantity', 0);
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
