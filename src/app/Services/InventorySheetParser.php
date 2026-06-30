<?php

declare(strict_types=1);

namespace App\Services;

class InventorySheetParser
{
    /**
     * Expand sheet rows into one entry per SKU (main and alternates).
     *
     * @param list<list<string|null>> $rows
     *
     * @return list<array{sheet_name: string, rack: string, bin: string, sku: string, is_main_sku: bool, sheet_row: int}>
     */
    public function parseSkuEntries(string $sheetName, array $rows): array
    {
        $entries = [];

        foreach ($rows as $index => $row) {
            if ($index === 0 && $this->isHeaderRow($row)) {
                continue;
            }

            $rack = $this->normalizeCell($row[0] ?? null);
            $bin  = $this->normalizeCell($row[1] ?? null);
            $main = $this->normalizeCell($row[2] ?? null);

            if ($rack === null && $bin === null && $main === null) {
                continue;
            }

            if ($rack === null || $bin === null) {
                continue;
            }

            $sheetRow = $index + 1;
            $seen = [];

            if ($main !== null) {
                $seen[strtoupper($main)] = true;
                $entries[] = [
                    'sheet_name'  => $sheetName,
                    'rack'        => $rack,
                    'bin'         => $bin,
                    'sku'         => $main,
                    'is_main_sku' => true,
                    'sheet_row'   => $sheetRow,
                ];
            }

            for ($column = 3, $columnMax = count($row); $column < $columnMax; $column++) {
                $alias = $this->normalizeCell($row[$column] ?? null);

                if ($alias === null) {
                    continue;
                }

                $key = strtoupper($alias);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $entries[] = [
                    'sheet_name'  => $sheetName,
                    'rack'        => $rack,
                    'bin'         => $bin,
                    'sku'         => $alias,
                    'is_main_sku' => false,
                    'sheet_row'   => $sheetRow,
                ];
            }
        }

        return $entries;
    }

    /**
     * @param list<string|null> $row
     */
    private function isHeaderRow(array $row): bool
    {
        $first = strtolower((string) ($row[0] ?? ''));

        return in_array($first, ['rack', 'racks'], true);
    }

    private function normalizeCell(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value) && floor($value) == $value) {
            $value = (string) (int) $value;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
