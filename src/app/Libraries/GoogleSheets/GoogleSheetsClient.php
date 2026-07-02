<?php

declare(strict_types=1);

namespace App\Libraries\GoogleSheets;

use App\Libraries\GoogleSheets\Exceptions\GoogleSheetsException;
use Config\GoogleSheets as GoogleSheetsConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GoogleSheetsClient
{
    private Client $http;

    public function __construct(private readonly GoogleSheetsConfig $config)
    {
        $this->http = new Client(['timeout' => $this->config->timeout]);
    }

    /**
     * @return list<string>
     */
    public function listSheetNames(): array
    {
        if ($this->config->apiKey !== '') {
            $names = $this->listSheetNamesViaApi();

            if ($names !== []) {
                return self::sortNamesDescending($names);
            }
        }

        return self::sortNamesDescending($this->configuredSheetNames());
    }

    public function sheetExists(string $sheetName): bool
    {
        $sheetName = trim($sheetName);

        if ($sheetName === '') {
            return false;
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
        $url = sprintf(
            'https://docs.google.com/spreadsheets/d/%s/gviz/tq?tqx=out:json&headers=1&sheet=%s',
            rawurlencode($this->config->spreadsheetId),
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
}
