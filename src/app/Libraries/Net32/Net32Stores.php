<?php

declare(strict_types=1);

namespace App\Libraries\Net32;

class Net32Stores
{
    public const LV    = 'lv';
    public const BROOK = 'brook';
    public const CORAL = 'coral';

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return [self::LV, self::BROOK, self::CORAL];
    }

    public static function label(string $store): string
    {
        return match ($store) {
            self::BROOK => 'Brook',
            self::CORAL => 'Coral',
            default     => 'LV',
        };
    }

    public static function qtyColumn(string $store): string
    {
        return match ($store) {
            self::BROOK => 'qty_brook',
            self::CORAL => 'qty_coral',
            default     => 'qty_lv',
        };
    }

    public static function existsColumn(string $store): string
    {
        return match ($store) {
            self::BROOK => 'net32_exists_brook',
            self::CORAL => 'net32_exists_coral',
            default     => 'net32_exists_lv',
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function existsFromRow(array $row, string $store): ?bool
    {
        $column = self::existsColumn($store);

        if (array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '') {
            return (bool) (int) $row[$column];
        }

        if (
            $store === self::LV
            && array_key_exists('sku_net32_exists', $row)
            && $row['sku_net32_exists'] !== null
            && $row['sku_net32_exists'] !== ''
        ) {
            return (bool) (int) $row['sku_net32_exists'];
        }

        return null;
    }

    /**
     * Split a typed quantity so LV gets the remainder and the total always adds up.
     *
     * @return array{lv: int, brook: int, coral: int}
     */
    public static function splitQuantity(int $total): array
    {
        $total = max(0, $total);
        $brook = (int) round($total * 0.20);
        $coral = (int) round($total * 0.20);

        return [
            self::LV    => $total - $brook - $coral,
            self::BROOK => $brook,
            self::CORAL => $coral,
        ];
    }

    /**
     * @param array<string, int> $split
     */
    public static function formatSplit(array $split): string
    {
        return sprintf(
            'LV %s, Brook %s, Coral %s',
            number_format((int) ($split[self::LV] ?? 0)),
            number_format((int) ($split[self::BROOK] ?? 0)),
            number_format((int) ($split[self::CORAL] ?? 0)),
        );
    }

    public static function formatStoreQty(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return number_format((int) $value);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function qtyFromRow(array $row, string $store): mixed
    {
        $column = self::qtyColumn($store);

        if (array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '') {
            return $row[$column];
        }

        if ($store === self::LV) {
            return $row['quantity'] ?? null;
        }

        return null;
    }

    public static function existsClass(?bool $exists, mixed $qty = null): string
    {
        if ($exists === false) {
            return 'is-out';
        }

        if ($qty !== null && $qty !== '' && (int) $qty === 0) {
            return 'is-zero';
        }

        if ($exists === true) {
            return 'is-in';
        }

        return 'is-unknown';
    }

    public static function existsLabel(?bool $exists, mixed $qty = null): string
    {
        if ($exists === false) {
            return 'not in Net32';
        }

        if ($qty !== null && $qty !== '' && (int) $qty === 0) {
            return 'in Net32, qty 0';
        }

        if ($exists === true) {
            return 'in Net32';
        }

        return 'not checked';
    }
}
