<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\GoogleSheets\Exceptions\GoogleSheetsException;
use App\Libraries\GoogleSheets\GoogleSheetsClient;
use App\Libraries\Net32\Exceptions\Net32ApiException;
use App\Libraries\Net32\Resources\ProductsResource;
use App\Models\InventoryModel;
use CodeIgniter\CLI\CLI;

class InventoryImportService
{
    private bool $showProgress = false;

    public function __construct(
        private readonly GoogleSheetsClient $sheets,
        private readonly InventoryModel $inventory,
        private readonly ProductsResource $products,
        private readonly InventorySheetParser $parser,
    ) {
    }

    /**
     * @return array{
     *     sheets: int,
     *     scanned: int,
     *     imported: int,
     *     skipped: int,
     *     ignored: int,
     *     errors: list<string>
     * }
     */
    public function importFromGoogleSheets(
        ?string $onlySheet = null,
        ?float $delaySeconds = null,
        bool $dryRun = false,
        bool $verbose = true,
        bool $logActivity = true,
        ?int $jobId = null,
    ): array {
        $delaySeconds ??= (float) config('Net32')->requestDelaySeconds;
        $this->showProgress = $verbose && is_cli();
        $errors     = [];
        $scanned    = 0;
        $imported   = 0;
        $skipped    = 0;
        $ignored    = 0;
        $syncedAt   = date('Y-m-d H:i:s');
        $sheetCount = 0;
        $grandTotal = 0;
        $discoveredSheets = [];

        try {
            $discoveredSheets = $this->sheets->refreshSheetNamesFromGoogle();
            $sheetNames = $this->resolveSheetNames($onlySheet);
        } catch (GoogleSheetsException $exception) {
            return $this->finish([
                'sheets'     => 0,
                'scanned'    => 0,
                'imported'   => 0,
                'skipped'    => 0,
                'ignored'    => 0,
                'errors'     => [$exception->getMessage()],
                'sheet_name' => $onlySheet,
            ], $dryRun, $logActivity);
        }

        if ($sheetNames === []) {
            return $this->finish([
                'sheets'     => 0,
                'scanned'    => 0,
                'imported'   => 0,
                'skipped'    => 0,
                'ignored'    => 0,
                'errors'     => ['No worksheet tabs configured. Set googleSheets.sheetNames or googleSheets.apiKey in .env.'],
                'sheet_name' => $onlySheet,
            ], $dryRun, $logActivity);
        }

        if ($this->showProgress) {
            $this->progressLine('Resolving sheet tabs and SKU entries...', 'cyan');
        }

        if ($jobId !== null) {
            service('inventoryImportJob')->updateProgress(
                $jobId,
                0,
                0,
                null,
                'Reading Google Sheets...',
            );
        }

        /** @var list<array{sheet: string, entries: list<array<string, mixed>>}> $sheetPlans */
        $sheetPlans = [];

        foreach ($sheetNames as $sheetName) {
            $cancelled = $this->buildCancelledImportResult(
                $jobId,
                $sheetCount,
                $scanned,
                $imported,
                $skipped,
                $ignored,
                $errors,
                $onlySheet,
                $discoveredSheets,
            );

            if ($cancelled !== null) {
                return $this->finish($cancelled, $dryRun, $logActivity);
            }

            if ($jobId !== null) {
                service('inventoryImportJob')->updateProgress(
                    $jobId,
                    0,
                    0,
                    $sheetName,
                    sprintf('Reading sheet "%s"...', $sheetName),
                );
            }

            try {
                $rows    = $this->sheets->fetchSheetRows($sheetName);
                $entries = $this->parser->parseSkuEntries($sheetName, $rows);
                $sheetPlans[] = [
                    'sheet'   => $sheetName,
                    'entries' => $entries,
                ];
                $this->sheets->rememberSheetName($sheetName);
                $grandTotal += count($entries);
            } catch (GoogleSheetsException $exception) {
                $errors[] = sprintf('Sheet "%s": %s', $sheetName, $exception->getMessage());
            }
        }

        if ($this->showProgress) {
            $this->progressLine(sprintf(
                'Found %d SKU entry(ies) across %d sheet tab(s).',
                $grandTotal,
                count($sheetPlans),
            ), 'cyan');
        }

        if ($jobId !== null) {
            service('inventoryImportJob')->updateProgress(
                $jobId,
                0,
                $grandTotal,
                null,
                sprintf('Found %d SKU(s) to process across %d sheet tab(s).', $grandTotal, count($sheetPlans)),
            );
        }

        foreach ($sheetPlans as $plan) {
            $cancelled = $this->buildCancelledImportResult(
                $jobId,
                $sheetCount,
                $scanned,
                $imported,
                $skipped,
                $ignored,
                $errors,
                $onlySheet,
                $discoveredSheets,
            );

            if ($cancelled !== null) {
                return $this->finish($cancelled, $dryRun, $logActivity);
            }

            $sheetName = $plan['sheet'];
            $entries   = $plan['entries'];
            $sheetCount++;
            $sheetTotal = count($entries);

            if ($this->showProgress) {
                $this->progressLine(sprintf(
                    'Sheet "%s": processing %d SKU(s).',
                    $sheetName,
                    $sheetTotal,
                ), 'light_gray');
            }

            foreach ($entries as $entry) {
                $cancelled = $this->buildCancelledImportResult(
                    $jobId,
                    $sheetCount,
                    $scanned,
                    $imported,
                    $skipped,
                    $ignored,
                    $errors,
                    $onlySheet,
                    $discoveredSheets,
                );

                if ($cancelled !== null) {
                    return $this->finish($cancelled, $dryRun, $logActivity);
                }

                $sku = $entry['sku'];
                $current = $scanned + 1;
                $prefix = $this->formatProgressPrefix($current, $grandTotal, $entry);

                if ($this->inventory->existsSku(
                    $entry['sheet_name'],
                    $entry['rack'],
                    $entry['bin'],
                    $sku,
                )) {
                    $skipped++;
                    $this->progressLine($prefix . 'skipped (already in database).', 'yellow');
                    $this->tickImportProgress($jobId, $scanned, $grandTotal, $entry, $sku);

                    continue;
                }

                $this->progressLine($prefix . 'checking Net32...', 'white');

                $this->sleepBetweenRequests($delaySeconds);

                try {
                    $offer = $this->products->findOfferByVpCode($sku);
                } catch (Net32ApiException $exception) {
                    $message = sprintf('SKU %s: %s', $sku, $exception->getMessage());
                    $errors[] = $message;
                    $this->progressLine($prefix . 'error: ' . $exception->getMessage(), 'red');
                    $this->tickImportProgress($jobId, $scanned, $grandTotal, $entry, $sku);

                    continue;
                }

                if ($offer === null) {
                    $ignored++;
                    $this->progressLine($prefix . 'not in Net32 (skipped).', 'light_gray');
                    $this->tickImportProgress($jobId, $scanned, $grandTotal, $entry, $sku);

                    continue;
                }

                if ($dryRun) {
                    $imported++;
                    $this->progressLine(sprintf(
                        '%swould import (qty %d).',
                        $prefix,
                        $offer['quantity'],
                    ), 'green');
                    $this->tickImportProgress($jobId, $scanned, $grandTotal, $entry, $sku);

                    continue;
                }

                $saved = $this->inventory->insertSkuRecord([
                    'sheet_name'       => $entry['sheet_name'],
                    'rack'             => $entry['rack'],
                    'bin'              => $entry['bin'],
                    'sku'              => $sku,
                    'is_main_sku'      => $entry['is_main_sku'] ? 1 : 0,
                    'name'             => $offer['name'],
                    'description'      => $offer['description'],
                    'quantity'         => $offer['quantity'],
                    'sku_net32_exists' => 1,
                    'net32_checked_at' => $syncedAt,
                    'synced_at'        => $syncedAt,
                ]);

                if ($saved) {
                    $imported++;
                    $this->progressLine(sprintf(
                        '%simported (qty %d).',
                        $prefix,
                        $offer['quantity'],
                    ), 'green');
                } else {
                    $errors[] = sprintf(
                        'Could not save %s/%s/%s SKU %s.',
                        $entry['sheet_name'],
                        $entry['rack'],
                        $entry['bin'],
                        $sku,
                    );
                    $this->progressLine($prefix . 'failed to save to database.', 'red');
                }

                $this->tickImportProgress($jobId, $scanned, $grandTotal, $entry, $sku);
            }

            if ($this->showProgress && $sheetTotal > 0) {
                $this->progressLine(sprintf('Finished sheet "%s".', $sheetName), 'cyan');
            }
        }

        return $this->finish([
            'sheets'            => $sheetCount,
            'scanned'           => $scanned,
            'imported'          => $imported,
            'skipped'           => $skipped,
            'ignored'           => $ignored,
            'errors'            => $errors,
            'sheet_name'        => $onlySheet,
            'discovered_sheets' => $discoveredSheets,
            'sheet_names'       => $this->sheets->getSheetNameOptions(),
        ], $dryRun, $logActivity);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function formatProgressPrefix(int $current, int $total, array $entry): string
    {
        $skuType = ! empty($entry['is_main_sku']) ? 'main' : 'alternate';

        return sprintf(
            '[%d/%d] Sheet "%s" row %d | Rack %s / Bin %s | SKU %s (%s) → ',
            $current,
            $total,
            $entry['sheet_name'],
            (int) ($entry['sheet_row'] ?? 0),
            $entry['rack'],
            $entry['bin'],
            $entry['sku'],
            $skuType,
        );
    }

    private function progressLine(string $message, string $color = 'white'): void
    {
        if (! $this->showProgress) {
            return;
        }

        CLI::write($message, $color);
    }

    /**
     * @param list<string> $errors
     * @param list<string> $discoveredSheets
     *
     * @return array<string, mixed>|null
     */
    private function buildCancelledImportResult(
        ?int $jobId,
        int $sheetCount,
        int $scanned,
        int $imported,
        int $skipped,
        int $ignored,
        array $errors,
        ?string $onlySheet,
        array $discoveredSheets,
    ): ?array {
        if ($jobId === null || ! service('inventoryImportJob')->isCancelRequested($jobId)) {
            return null;
        }

        return [
            'sheets'            => $sheetCount,
            'scanned'           => $scanned,
            'imported'          => $imported,
            'skipped'           => $skipped,
            'ignored'           => $ignored,
            'errors'            => $errors,
            'sheet_name'        => $onlySheet,
            'discovered_sheets' => $discoveredSheets,
            'sheet_names'       => $this->sheets->getSheetNameOptions(),
            'cancelled'         => true,
            'total'             => max($scanned, 0),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function tickImportProgress(
        ?int $jobId,
        int &$scanned,
        int $grandTotal,
        array $entry,
        string $sku,
    ): void {
        $scanned++;

        if ($jobId === null) {
            return;
        }

        service('inventoryImportJob')->updateProgress(
            $jobId,
            $scanned,
            $grandTotal,
            (string) $entry['sheet_name'],
            sprintf(
                'Processed %d of %d SKUs — %s (%s/%s/%s).',
                $scanned,
                $grandTotal,
                $sku,
                $entry['sheet_name'],
                $entry['rack'],
                $entry['bin'],
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function resolveSheetNames(?string $onlySheet): array
    {
        if ($onlySheet !== null && $onlySheet !== '') {
            return [$onlySheet];
        }

        return $this->sheets->listSheetNames();
    }

    /**
     * @param array{
     *     sheets: int,
     *     scanned: int,
     *     imported: int,
     *     skipped: int,
     *     ignored: int,
     *     errors: list<string>
     * } $result
     *
     * @return array{
     *     sheets: int,
     *     scanned: int,
     *     imported: int,
     *     skipped: int,
     *     ignored: int,
     *     errors: list<string>
     * }
     */
    private function finish(array $result, bool $dryRun, bool $logActivity = true): array
    {
        if (! $dryRun && $logActivity) {
            service('activityLog')->logInventoryImport($result);
        }

        return $result;
    }

    private function sleepBetweenRequests(float $delaySeconds): void
    {
        if ($delaySeconds <= 0) {
            return;
        }

        usleep((int) round($delaySeconds * 1_000_000));
    }
}
