<?php

declare(strict_types=1);

namespace App\Libraries\GoogleSheets;

use App\Libraries\GoogleSheets\Exceptions\GoogleSheetsException;
use Config\GoogleSheets as GoogleSheetsConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GoogleSheetsClient
{
    private const CACHE_FILENAME = 'google_sheet_tabs.json';

    private Client $http;

    public function __construct(private readonly GoogleSheetsConfig $config)
    {
        $this->http = new Client(['timeout' => $this->config->timeout]);
    }

    public function warehouseLogicalSheetName(): string
    {
        $name = trim($this->config->warehouseInventorySheetName);

        return $name !== '' ? $name : 'WH';
    }

    public function hasWarehouseSource(): bool
    {
        return trim($this->config->warehouseSpreadsheetId) !== ''
            && $this->configuredWarehouseTabNames() !== [];
    }

    public function isWarehouseLogicalSheet(string $sheetName): bool
    {
        return $this->hasWarehouseSource()
            && strcasecmp(trim($sheetName), $this->warehouseLogicalSheetName()) === 0;
    }

    /**
     * @return list<string>
     */
    public function listWarehouseTabNames(): array
    {
        return $this->configuredWarehouseTabNames();
    }

    /**
     * @return list<string>
     */
    public function listSheetNames(): array
    {
        if ($this->config->apiKey !== '') {
            $names = $this->listSheetNamesViaApi();

            if ($names !== []) {
                $this->persistCachedSheetNames($names);

                return self::sortNamesDescending($this->withWarehouseLogicalSheet($names));
            }
        }

        return self::sortNamesDescending($this->mergedSheetNames());
    }

    /**
     * Sheet tabs in natural ascending order (1, 2, 3 …) for full-spreadsheet processing.
     *
     * @return list<string>
     */
    public function listSheetNamesAscending(): array
    {
        if ($this->config->apiKey !== '') {
            $names = $this->listSheetNamesViaApi();

            if ($names !== []) {
                $this->persistCachedSheetNames($names);

                return self::sortNamesAscending($this->withWarehouseLogicalSheet($names));
            }
        }

        return self::sortNamesAscending($this->mergedSheetNames());
    }

    /**
     * Sheet tabs for filters and form dropdowns (configured + cached + API when available).
     *
     * @return list<string>
     */
    public function getSheetNameOptions(): array
    {
        return self::sortNamesAscending($this->mergedSheetNames());
    }

    /**
     * Refresh cached sheet tabs from Google Sheets before an import.
     *
     * @return list<string> Newly discovered tab names not previously cached or configured
     */
    public function refreshSheetNamesFromGoogle(): array
    {
        if ($this->config->apiKey === '') {
            return [];
        }

        $apiNames = $this->listSheetNamesViaApi();

        if ($apiNames === []) {
            return [];
        }

        $before = array_map(
            static fn (string $name): string => strtolower($name),
            $this->mergedSheetNames(),
        );
        $this->persistCachedSheetNames($apiNames);

        $discovered = [];

        foreach ($apiNames as $name) {
            if (! in_array(strtolower($name), $before, true)) {
                $discovered[] = $name;
            }
        }

        return $discovered;
    }

    public function rememberSheetName(string $sheetName): void
    {
        $sheetName = trim($sheetName);

        if ($sheetName === '') {
            return;
        }

        $this->persistCachedSheetNames([$sheetName]);
    }

    public function sheetExists(string $sheetName): bool
    {
        $sheetName = trim($sheetName);

        if ($sheetName === '') {
            return false;
        }

        if ($this->isWarehouseLogicalSheet($sheetName)) {
            return true;
        }

        foreach ($this->listSheetNames() as $name) {
            if (strcasecmp($name, $sheetName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    public static function sortNamesDescending(array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map(
            static fn (string $name): string => trim($name),
            $names,
        ))));

        usort($names, static fn (string $a, string $b): int => strnatcasecmp($b, $a));

        return $names;
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    public static function sortNamesAscending(array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map(
            static fn (string $name): string => trim($name),
            $names,
        ))));

        usort($names, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

        return $names;
    }

    /**
     * @return list<list<string|null>>
     */
    public function fetchSheetRows(string $sheetName): array
    {
        return $this->fetchSpreadsheetSheetRows($this->config->spreadsheetId, $sheetName);
    }

    /**
     * @return list<list<string|null>>
     */
    public function fetchWarehouseTabRows(string $tabName): array
    {
        $spreadsheetId = trim($this->config->warehouseSpreadsheetId);

        if ($spreadsheetId === '') {
            throw new GoogleSheetsException('Warehouse spreadsheet is not configured. Set googleSheets.warehouseSpreadsheetId in .env.');
        }

        // headers=0 so we keep the raw first row and detect Location/SKU ourselves.
        return $this->fetchSpreadsheetSheetRows($spreadsheetId, $tabName, false);
    }

    /**
     * @return list<list<string|null>>
     */
    public function fetchSpreadsheetSheetRows(string $spreadsheetId, string $sheetName, bool $headers = true): array
    {
        $spreadsheetId = trim($spreadsheetId);
        $sheetName     = trim($sheetName);

        if ($spreadsheetId === '' || $sheetName === '') {
            throw new GoogleSheetsException('Spreadsheet ID and sheet tab name are required.');
        }

        $url = sprintf(
            'https://docs.google.com/spreadsheets/d/%s/gviz/tq?tqx=out:json&headers=%d&sheet=%s',
            rawurlencode($spreadsheetId),
            $headers ? 1 : 0,
            rawurlencode($sheetName),
        );

        try {
            $body = (string) $this->http->get($url)->getBody();
        } catch (GuzzleException $exception) {
            throw new GoogleSheetsException(
                sprintf('Failed to fetch sheet "%s": %s', $sheetName, $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (! preg_match('/setResponse\((.*)\);\s*$/s', $body, $matches)) {
            throw new GoogleSheetsException(sprintf('Unexpected response for sheet "%s".', $sheetName));
        }

        $payload = json_decode($matches[1], true);

        if (! is_array($payload) || ($payload['status'] ?? '') !== 'ok') {
            throw new GoogleSheetsException(sprintf('Google Sheets returned an error for sheet "%s".', $sheetName));
        }

        $rows = $payload['table']['rows'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $parsed = [];

        foreach ($rows as $row) {
            $cells = $row['c'] ?? [];
            $line  = [];

            foreach ($cells as $cell) {
                $line[] = is_array($cell) ? ($cell['v'] ?? null) : null;
            }

            $parsed[] = $line;
        }

        return $parsed;
    }

    /**
     * @return list<string>
     */
    private function listSheetNamesViaApi(): array
    {
        $url = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%s?fields=sheets.properties.title&key=%s',
            rawurlencode($this->config->spreadsheetId),
            rawurlencode($this->config->apiKey),
        );

        try {
            $payload = json_decode((string) $this->http->get($url)->getBody(), true);
        } catch (GuzzleException) {
            return [];
        }

        if (! is_array($payload)) {
            return [];
        }

        $names = [];

        foreach ($payload['sheets'] ?? [] as $sheet) {
            $title = $sheet['properties']['title'] ?? null;

            if (is_string($title) && $title !== '') {
                $names[] = $title;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function configuredSheetNames(): array
    {
        $names = array_filter(array_map('trim', explode(',', $this->config->sheetNames)));

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function configuredWarehouseTabNames(): array
    {
        $names = array_filter(array_map('trim', explode(',', $this->config->warehouseSheetNames)));

        return array_values(array_unique($names));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function withWarehouseLogicalSheet(array $names): array
    {
        if (! $this->hasWarehouseSource()) {
            return $names;
        }

        return array_values(array_unique(array_merge($names, [$this->warehouseLogicalSheetName()])));
    }

    /**
     * @return list<string>
     */
    private function mergedSheetNames(): array
    {
        return $this->withWarehouseLogicalSheet(array_values(array_unique(array_merge(
            $this->configuredSheetNames(),
            $this->loadCachedSheetNames(),
        ))));
    }

    /**
     * @return list<string>
     */
    private function loadCachedSheetNames(): array
    {
        $path = $this->cacheFilePath();

        if (! is_file($path)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (! is_array($payload) || ! is_array($payload['names'] ?? null)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $name): string => trim((string) $name),
            $payload['names'],
        ))));
    }

    /**
     * @param list<string> $names
     */
    private function persistCachedSheetNames(array $names): void
    {
        $merged = array_values(array_unique(array_merge(
            $this->loadCachedSheetNames(),
            array_values(array_filter(array_map(
                static fn (string $name): string => trim($name),
                $names,
            ))),
        )));

        $path = $this->cacheFilePath();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return;
        }

        file_put_contents($path, json_encode([
            'names'      => $merged,
            'updated_at' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function cacheFilePath(): string
    {
        return rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . self::CACHE_FILENAME;
    }
}
