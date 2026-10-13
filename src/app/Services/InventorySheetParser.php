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
     * Warehouse layout: Location (A1) | SKU | Description | Expiration? | Alt SKUs…
     * Always stores sheet_name as the configured warehouse logical name (WH).
     *
     * @param list<list<string|null>> $rows
     *
     * @return list<array{sheet_name: string, rack: string, bin: string, sku: string, is_main_sku: bool, sheet_row: int}>
     */
    public function parseWarehouseSkuEntries(string $logicalSheetName, array $rows): array
    {
        $logicalSheetName = trim($logicalSheetName) !== '' ? trim($logicalSheetName) : 'WH';
        $entries = [];

        foreach ($rows as $index => $row) {
            if ($index === 0 && $this->isWarehouseHeaderRow($row)) {
                continue;
            }

            $location = $this->normalizeCell($row[0] ?? null);
            $parsed   = $this->parseWarehouseLocation($location);

            if ($parsed === null) {
                continue;
            }

            $main = $this->normalizeCell($row[1] ?? null);

            if ($main === null || ! $this->looksLikeSku($main)) {
                continue;
            }

            $sheetRow = $index + 1;
            $seen     = [strtoupper($main) => true];
            $entries[] = [
                'sheet_name'  => $logicalSheetName,
                'rack'        => $parsed['rack'],
                'bin'         => $parsed['bin'],
                'sku'         => $main,
                'is_main_sku' => true,
                'sheet_row'   => $sheetRow,
            ];

            // Live warehouse layout: A=location, B=SKU, C=blank, D=description, E=expiration, F+=alt SKUs.
            for ($column = 5, $columnMax = count($row); $column < $columnMax; $column++) {
                $alias = $this->normalizeCell($row[$column] ?? null);

                if ($alias === null || ! $this->looksLikeSku($alias)) {
                    continue;
                }

                $key = strtoupper($alias);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $entries[] = [
                    'sheet_name'  => $logicalSheetName,
                    'rack'        => $parsed['rack'],
                    'bin'         => $parsed['bin'],
                    'sku'         => $alias,
                    'is_main_sku' => false,
                    'sheet_row'   => $sheetRow,
                ];
            }
        }

        return $entries;
    }

    /**
     * @return array{rack: string, bin: string}|null
     */
    public function parseWarehouseLocation(?string $location): ?array
    {
        if ($location === null) {
            return null;
        }

        if (! preg_match('/^([A-Za-z]+)\s*[-_]?\s*(\d+)$/', $location, $matches)) {
            return null;
        }

        return [
            'rack' => strtoupper($matches[1]),
            'bin'  => (string) ((int) $matches[2]),
        ];
    }

    /**
     * @param list<string|null> $row
     */
    private function isHeaderRow(array $row): bool
    {
        $first = strtolower((string) ($row[0] ?? ''));

        return in_array($first, ['rack', 'racks'], true);
    }

    /**
     * @param list<string|null> $row
     */
    private function isWarehouseHeaderRow(array $row): bool
    {
        $first = strtolower((string) ($row[0] ?? ''));

        return in_array($first, ['sku', 'location', 'bin', 'rack'], true);
    }

    private function looksLikeSku(string $value): bool
    {
        if ($value === '' || str_contains($value, '#REF')) {
            return false;
        }

        // Expiration / manufacturing notes.
        if (preg_match('/\b(mfg|exp|expiration|date)\b/i', $value)) {
            return false;
        }

        if (preg_match('/^\d{1,2}\/\d{1,2}(\/\d{2,4})?$/', $value)) {
            return false;
        }

        // Descriptions are usually long prose; SKUs are compact codes.
        if (strlen($value) > 48) {
            return false;
        }

        // Plain English names like "Softguard XS" — require a digit or hyphen if spaced.
        if (str_contains($value, ' ') && ! preg_match('/[0-9\-]/', $value)) {
            return false;
        }

        if (str_word_count($value) > 3) {
            return false;
        }

        return (bool) preg_match('/[A-Za-z0-9]/', $value);
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
