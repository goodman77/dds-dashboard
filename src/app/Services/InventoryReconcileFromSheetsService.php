<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\GoogleSheets\Exceptions\GoogleSheetsException;
use App\Libraries\GoogleSheets\GoogleSheetsClient;
use App\Libraries\Net32\Exceptions\Net32ApiException;
use App\Libraries\Net32\Resources\ProductsResource;
use App\Models\InventoryModel;
use CodeIgniter\CLI\CLI;

class InventoryReconcileFromSheetsService
{
    public const ALL_SHEETS = '*';

    private const JOB_POLL_SKU_INTERVAL = 25;

    private const JOB_POLL_SECONDS = 1.0;

    private bool $showProgress = false;

    private int $lastJobPollScanned = 0;

    private float $lastJobPollAt = 0.0;

    public function __construct(
        private readonly GoogleSheetsClient $sheets,
        private readonly InventoryModel $inventory,
        private readonly ProductsResource $products,
        private readonly InventorySheetParser $parser,
    ) {
    }

    /**
     * @return array{
     *     sheet_name: string|null,
     *     sheets: int,
     *     scanned: int,
     *     added: int,
     *     updated: int,
     *     removed: int,
     *     unchanged: int,
     *     ignored: int,
     *     errors: list<string>,
     *     discovered_sheets?: list<string>,
     *     sheet_names?: list<string>
     * }
     */
    public function reconcileFromGoogleSheets(
        ?string $onlySheet = null,
        ?float $delaySeconds = null,
        bool $dryRun = false,
        bool $verbose = true,
        bool $logActivity = true,
        ?int $jobId = null,
    ): array {
        $delaySeconds ??= (float) config('Net32')->requestDelaySeconds;
        $this->showProgress = $verbose && is_cli();
        $syncedAt           = date('Y-m-d H:i:s');
        $this->lastJobPollScanned = 0;
        $this->lastJobPollAt      = 0.0;

        $added      = 0;
        $updated    = 0;
        $removed    = 0;
        $unchanged  = 0;
        $ignored    = 0;
        $errors     = [];
        $sheetCount = 0;
        $scanned    = 0;
        $discoveredSheets = [];

        try {
            $discoveredSheets = $this->sheets->refreshSheetNamesFromGoogle();
            $sheetNames       = $this->resolveSheetNames($onlySheet);
        } catch (GoogleSheetsException $exception) {
            return $this->finish([
                'sheet_name' => $onlySheet,
                'sheets'     => 0,
                'scanned'    => 0,
                'added'      => 0,
                'updated'    => 0,
                'removed'    => 0,
                'unchanged'  => 0,
                'ignored'    => 0,
                'errors'     => [$exception->getMessage()],
            ], $dryRun, $logActivity);
        }

        if ($sheetNames === []) {
            return $this->finish([
                'sheet_name' => $onlySheet,
                'sheets'     => 0,
                'scanned'    => 0,
                'added'      => 0,
                'updated'    => 0,
                'removed'    => 0,
                'unchanged'  => 0,
                'ignored'    => 0,
                'errors'     => ['No worksheet tabs configured. Set googleSheets.sheetNames or googleSheets.apiKey in .env.'],
            ], $dryRun, $logActivity);
        }

        if ($this->showProgress) {
            $this->progressLine('Reading Google Sheets and building reconcile plan...', 'cyan');
        }

        /** @var array<string, array<string, array<string, mixed>>> $entriesBySheet location key => entry, keyed by sheet */
        $entriesBySheet = [];
        $globalSkuOwner = [];

        foreach ($sheetNames as $sheetName) {
            $sheetName = (string) $sheetName;

            try {
                $rows    = $this->sheets->fetchSheetRows($sheetName);
                $entries = $this->parser->parseSkuEntries($sheetName, $rows);
                $this->sheets->rememberSheetName($sheetName);
                $sheetCount++;

                $sheetMap = [];

                foreach ($entries as $entry) {
                    $skuKey   = strtoupper($entry['sku']);
                    $entryKey = $this->locationKey(
                        (string) $entry['sheet_name'],
                        (string) $entry['rack'],
                        (string) $entry['bin'],
                        (string) $entry['sku'],
                    );

                    if (isset($sheetMap[$entryKey])) {
                        $errors[] = sprintf(
                            'Sheet "%s": SKU %s appears more than once in rack %s / bin %s (rows %d and %d).',
                            $sheetName,
                            $entry['sku'],
                            $entry['rack'],
                            $entry['bin'],
                            (int) $sheetMap[$entryKey]['sheet_row'],
                            (int) $entry['sheet_row'],
                        );

                        continue;
                    }

                    if (isset($globalSkuOwner[$skuKey]) && $globalSkuOwner[$skuKey] !== $sheetName) {
                        $errors[] = sprintf(
                            'SKU %s appears on sheet "%s" and "%s". Reconcile one sheet at a time or fix the sheet.',
                            $entry['sku'],
                            $globalSkuOwner[$skuKey],
                            $sheetName,
                        );

                        continue;
                    }

                    $sheetMap[$entryKey]       = $entry;
                    $globalSkuOwner[$skuKey]   = $sheetName;
                }

                $entriesBySheet[(string) $sheetName] = $sheetMap;
            } catch (GoogleSheetsException $exception) {
                $errors[] = sprintf('Sheet "%s": %s', $sheetName, $exception->getMessage());
            }
        }

        $grandTotal = array_sum(array_map('count', $entriesBySheet));

        if ($this->showProgress) {
            $this->progressLine(sprintf(
                'Found %d unique SKU(s) across %d sheet tab(s).',
                $grandTotal,
                count($entriesBySheet),
            ), 'cyan');
        }

        if ($jobId !== null) {
            service('inventoryImportJob')->updateProgress(
                $jobId,
                0,
                max($grandTotal, 1),
                null,
                sprintf('Reconciling %d SKU(s) across %d sheet tab(s)...', $grandTotal, count($entriesBySheet)),
                [
                    'added'     => 0,
                    'updated'   => 0,
                    'removed'   => 0,
                    'unchanged' => 0,
                    'ignored'   => 0,
                ],
            );
        }

        $inventoryByLocation = $this->indexInventoryByLocation();

        foreach ($entriesBySheet as $sheetName => $sheetEntries) {
            $sheetName = (string) $sheetName;

            foreach ($sheetEntries as $entry) {
                $cancelled = $this->pollReconcileJob(
                    $jobId,
                    $onlySheet,
                    $sheetCount,
                    $scanned,
                    $grandTotal,
                    $entry,
                    $added,
                    $updated,
                    $removed,
                    $unchanged,
                    $ignored,
                    $errors,
                    $discoveredSheets ?? [],
                    false,
                );

                if ($cancelled !== null) {
                    return $this->finish($cancelled, $dryRun, $logActivity);
                }

                $scanned++;
                $sku      = $entry['sku'];
                $entryKey = $this->locationKey(
                    (string) $entry['sheet_name'],
                    (string) $entry['rack'],
                    (string) $entry['bin'],
                    $sku,
                );
                $prefix   = $this->formatProgressPrefix($scanned, $grandTotal, $entry);
                $existing = $inventoryByLocation[$entryKey] ?? null;
                $forcePoll = false;

                if ($existing === null) {
                    $cancelled = $this->pollReconcileJob(
                        $jobId,
                        $onlySheet,
                        $sheetCount,
                        $scanned,
                        $grandTotal,
                        $entry,
                        $added,
                        $updated,
                        $removed,
                        $unchanged,
                        $ignored,
                        $errors,
                        $discoveredSheets ?? [],
                        true,
                    );

                    if ($cancelled !== null) {
                        return $this->finish($cancelled, $dryRun, $logActivity);
                    }

                    $template    = $this->findInventoryRowBySku($inventoryByLocation, $sku);
                    $addedResult = $template !== null
                        ? $this->addEntryFromExistingInventory($entry, $template, $syncedAt, $dryRun, $prefix, $errors)
                        : $this->addEntryFromSheet($entry, $syncedAt, $delaySeconds, $dryRun, $prefix, $errors);

                    if ($addedResult === 'added') {
                        $added++;
                        $saved = $this->inventory->findByPosition(
                            (string) $entry['sheet_name'],
                            (string) $entry['rack'],
                            (string) $entry['bin'],
                            $sku,
                        );
                        $inventoryByLocation[$entryKey] = $saved ?? array_merge($entry, [
                            'sku'        => $sku,
                            'sheet_name' => $entry['sheet_name'],
                            'rack'       => $entry['rack'],
                            'bin'        => $entry['bin'],
                        ]);
                    } elseif ($addedResult === 'ignored') {
                        $ignored++;
                    }

                    $forcePoll = true;
                } else {
                    $changes = $this->detectLocationChanges($existing, $entry);

                    if ($changes === []) {
                        $unchanged++;
                    } elseif ($dryRun) {
                        $updated++;
                        $forcePoll = true;
                        $this->progressLine($prefix . 'would update location.', 'yellow');
                    } else {
                        $record = array_merge($existing, [
                            'sheet_name'  => $entry['sheet_name'],
                            'rack'        => $entry['rack'],
                            'bin'         => $entry['bin'],
                            'is_main_sku' => ! empty($entry['is_main_sku']) ? 1 : 0,
                        ]);

                        if ($this->inventory->update((int) $existing['id'], [
                            'sheet_name'  => $record['sheet_name'],
                            'rack'        => $record['rack'],
                            'bin'         => $record['bin'],
                            'is_main_sku' => $record['is_main_sku'],
                        ])) {
                            $updated++;
                            $inventoryByLocation[$entryKey] = $record;
                            $forcePoll = true;
                            service('activityLog')->logInventoryEdit(
                                $existing,
                                $record,
                                (int) $existing['id'],
                                $changes,
                            );
                            $this->progressLine($prefix . 'updated location.', 'green');
                        } else {
                            $errors[] = sprintf('Could not update SKU %s.', $sku);
                            $forcePoll = true;
                            $this->progressLine($prefix . 'failed to update.', 'red');
                        }
                    }
                }

                $cancelled = $this->pollReconcileJob(
                    $jobId,
                    $onlySheet,
                    $sheetCount,
                    $scanned,
                    $grandTotal,
                    $entry,
                    $added,
                    $updated,
                    $removed,
                    $unchanged,
                    $ignored,
                    $errors,
                    $discoveredSheets ?? [],
                    $forcePoll || $scanned >= $grandTotal,
                );

                if ($cancelled !== null) {
                    return $this->finish($cancelled, $dryRun, $logActivity);
                }
            }
        }

        foreach ($entriesBySheet as $sheetName => $sheetEntries) {
            $sheetName = (string) $sheetName;
            $entryForPoll = ['sheet_name' => $sheetName];

            $cancelled = $this->pollReconcileJob(
                $jobId,
                $onlySheet,
                $sheetCount,
                $scanned,
                $grandTotal,
                $entryForPoll,
                $added,
                $updated,
                $removed,
                $unchanged,
                $ignored,
                $errors,
                $discoveredSheets ?? [],
                true,
            );

            if ($cancelled !== null) {
                return $this->finish($cancelled, $dryRun, $logActivity);
            }

            if ($jobId !== null) {
                service('inventoryImportJob')->updateProgress(
                    $jobId,
                    $scanned,
                    max($grandTotal, 1),
                    $sheetName,
                    sprintf('Removing SKUs not on sheet "%s"...', $sheetName),
                    [
                        'added'     => $added,
                        'updated'   => $updated,
                        'removed'   => $removed,
                        'unchanged' => $unchanged,
                        'ignored'   => $ignored,
                    ],
                );
            }

            $keptLocationKeys = [];

            foreach ($this->inventory->findBySheetName($sheetName) as $row) {
                $locationKey = $this->locationKey(
                    (string) ($row['sheet_name'] ?? ''),
                    (string) ($row['rack'] ?? ''),
                    (string) ($row['bin'] ?? ''),
                    (string) ($row['sku'] ?? ''),
                );
                $onSheet    = isset($sheetEntries[$locationKey]);
                $isExtraCopy = $onSheet && isset($keptLocationKeys[$locationKey]);

                if ($onSheet && ! $isExtraCopy) {
                    $keptLocationKeys[$locationKey] = true;

                    continue;
                }

                $sku    = (string) $row['sku'];
                $rowId  = (int) ($row['id'] ?? 0);
                $reason = $isExtraCopy ? 'duplicate in the same bin' : 'not on sheet at this bin';
                $prefix = sprintf(
                    'Sheet "%s" | Rack %s / Bin %s | SKU %s → ',
                    $sheetName,
                    $row['rack'] ?? '',
                    $row['bin'] ?? '',
                    $sku,
                );

                if ($dryRun) {
                    $removed++;
                    unset($inventoryByLocation[$locationKey]);
                    $this->progressLine($prefix . 'would remove (' . $reason . ').', 'yellow');

                    continue;
                }

                if ($rowId > 0 && $this->inventory->delete($rowId)) {
                    $removed++;
                    unset($inventoryByLocation[$locationKey]);
                    $this->progressLine($prefix . 'removed (' . $reason . ').', 'yellow');
                } elseif ($rowId <= 0) {
                    $removed++;
                    unset($inventoryByLocation[$locationKey]);
                } else {
                    $errors[] = sprintf('Could not remove SKU %s (id %d).', $sku, $rowId);
                    $this->progressLine($prefix . 'failed to remove.', 'red');
                }
            }
        }

        return $this->finish([
            'sheet_name'        => $onlySheet,
            'sheets'            => $sheetCount,
            'scanned'           => $scanned,
            'added'             => $added,
            'updated'           => $updated,
            'removed'           => $removed,
            'unchanged'         => $unchanged,
            'ignored'           => $ignored,
            'errors'            => $errors,
            'discovered_sheets' => $discoveredSheets,
            'sheet_names'       => $this->sheets->getSheetNameOptions(),
        ], $dryRun, $logActivity);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return 'added'|'ignored'|'failed'
     */
    private function addEntryFromSheet(
        array $entry,
        string $syncedAt,
        float $delaySeconds,
        bool $dryRun,
        string $prefix,
        array &$errors,
    ): string {
        $sku = $entry['sku'];

        $this->progressLine($prefix . 'checking Net32...', 'white');
        $this->sleepBetweenRequests($delaySeconds);

        try {
            $offer = $this->products->findOfferByVpCode($sku);
        } catch (Net32ApiException $exception) {
            $message = sprintf('SKU %s: %s', $sku, $exception->getMessage());
            $errors[] = $message;
            $this->progressLine($prefix . 'error: ' . $exception->getMessage(), 'red');

            return 'failed';
        }

        if ($offer === null) {
            $this->progressLine($prefix . 'not in Net32 (skipped).', 'light_gray');

            return 'ignored';
        }

        if ($dryRun) {
            $this->progressLine(sprintf('%swould add (qty %d).', $prefix, $offer['quantity']), 'green');

            return 'added';
        }

        $saved = $this->inventory->insertSkuRecord([
            'sheet_name'       => $entry['sheet_name'],
            'rack'             => $entry['rack'],
            'bin'              => $entry['bin'],
            'sku'              => $sku,
            'is_main_sku'      => ! empty($entry['is_main_sku']) ? 1 : 0,
            'name'             => $offer['name'],
            'description'      => $offer['description'],
            'quantity'         => $offer['quantity'],
            'sku_net32_exists' => 1,
            'net32_checked_at' => $syncedAt,
            'synced_at'        => $syncedAt,
        ]);

        if ($saved) {
            $this->progressLine(sprintf('%sadded (qty %d).', $prefix, $offer['quantity']), 'green');

            return 'added';
        }

        $errors[] = sprintf('Could not save %s/%s/%s SKU %s.', $entry['sheet_name'], $entry['rack'], $entry['bin'], $sku);
        $this->progressLine($prefix . 'failed to save to database.', 'red');

        return 'failed';
    }

    /**
     * Add a SKU at another bin using name/qty already stored for that SKU (no Net32 call).
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $source
     *
     * @return 'added'|'failed'
     */
    private function addEntryFromExistingInventory(
        array $entry,
        array $source,
        string $syncedAt,
        bool $dryRun,
        string $prefix,
        array &$errors,
    ): string {
        $sku = $entry['sku'];

        if ($dryRun) {
            $this->progressLine($prefix . 'would add (already in inventory at another bin).', 'green');

            return 'added';
        }

        $saved = $this->inventory->insertSkuRecord([
            'sheet_name'       => $entry['sheet_name'],
            'rack'             => $entry['rack'],
            'bin'              => $entry['bin'],
            'sku'              => $sku,
            'is_main_sku'      => ! empty($entry['is_main_sku']) ? 1 : 0,
            'name'             => $source['name'] ?? null,
            'description'      => $source['description'] ?? null,
            'quantity'         => (int) ($source['quantity'] ?? 0),
            'sku_net32_exists' => $source['sku_net32_exists'] ?? 1,
            'net32_checked_at' => $source['net32_checked_at'] ?? $syncedAt,
            'synced_at'        => $syncedAt,
        ]);

        if ($saved) {
            $this->progressLine($prefix . 'added (copied from existing inventory row).', 'green');

            return 'added';
        }

        $errors[] = sprintf('Could not save %s/%s/%s SKU %s.', $entry['sheet_name'], $entry['rack'], $entry['bin'], $sku);
        $this->progressLine($prefix . 'failed to save to database.', 'red');

        return 'failed';
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $entry
     *
     * @return array<string, array{label: string, from: mixed, to: mixed}>
     */
    private function detectLocationChanges(array $existing, array $entry): array
    {
        $record = [
            'sheet_name'  => $entry['sheet_name'],
            'rack'        => $entry['rack'],
            'bin'         => $entry['bin'],
            'sku'         => $entry['sku'],
            'is_main_sku' => ! empty($entry['is_main_sku']) ? 1 : 0,
            'name'        => $existing['name'] ?? null,
            'description' => $existing['description'] ?? null,
            'quantity'    => $existing['quantity'] ?? 0,
        ];

        return service('activityLog')->detectInventoryChanges($existing, $record);
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

    /**
     * @return list<string>
     */
    private function resolveSheetNames(?string $onlySheet): array
    {
        if ($onlySheet !== null && $onlySheet !== '' && $onlySheet !== self::ALL_SHEETS) {
            return [$onlySheet];
        }

        return $this->sheets->listSheetNamesAscending();
    }

    /**
     * @param array{
     *     sheet_name: string|null,
     *     sheets: int,
     *     scanned: int,
     *     added: int,
     *     updated: int,
     *     removed: int,
     *     unchanged: int,
     *     ignored: int,
     *     errors: list<string>
     * } $result
     *
     * @return array{
     *     sheet_name: string|null,
     *     sheets: int,
     *     scanned: int,
     *     added: int,
     *     updated: int,
     *     removed: int,
     *     unchanged: int,
     *     ignored: int,
     *     errors: list<string>
     * }
     */
    private function finish(array $result, bool $dryRun, bool $logActivity): array
    {
        if (! $dryRun && $logActivity) {
            service('activityLog')->logInventoryReconcile($result);
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexInventoryByLocation(): array
    {
        $index = [];

        foreach ($this->inventory->findAllForQuantitySync() as $row) {
            $key = $this->locationKey(
                (string) ($row['sheet_name'] ?? ''),
                (string) ($row['rack'] ?? ''),
                (string) ($row['bin'] ?? ''),
                (string) ($row['sku'] ?? ''),
            );

            if ($key === '|||' || isset($index[$key])) {
                continue;
            }

            $index[$key] = $row;
        }

        return $index;
    }

    /**
     * @param array<string, array<string, mixed>> $inventoryByLocation
     *
     * @return array<string, mixed>|null
     */
    private function findInventoryRowBySku(array $inventoryByLocation, string $sku): ?array
    {
        $skuKey = strtoupper(trim($sku));

        if ($skuKey === '') {
            return null;
        }

        foreach ($inventoryByLocation as $row) {
            if (strtoupper(trim((string) ($row['sku'] ?? ''))) === $skuKey) {
                return $row;
            }
        }

        return null;
    }

    private function locationKey(string $sheetName, string $rack, string $bin, string $sku): string
    {
        return strtoupper(trim($sheetName))
            . '|' . strtoupper(trim($rack))
            . '|' . strtoupper(trim($bin))
            . '|' . strtoupper(trim($sku));
    }

    /**
     * Check cancel and write job progress at most every 25 SKUs or once per second,
     * unless $force is true (adds, updates, Net32 lookups, last SKU).
     *
     * @param array<string, mixed> $entry
     * @param list<string> $errors
     * @param list<string> $discoveredSheets
     *
     * @return array<string, mixed>|null
     */
    private function pollReconcileJob(
        ?int $jobId,
        ?string $onlySheet,
        int $sheetCount,
        int $scanned,
        int $grandTotal,
        array $entry,
        int $added,
        int $updated,
        int $removed,
        int $unchanged,
        int $ignored,
        array $errors,
        array $discoveredSheets,
        bool $force,
    ): ?array {
        if ($jobId === null) {
            return null;
        }

        $now = microtime(true);
        $due = $force
            || ($scanned - $this->lastJobPollScanned) >= self::JOB_POLL_SKU_INTERVAL
            || ($now - $this->lastJobPollAt) >= self::JOB_POLL_SECONDS;

        if (! $due) {
            return null;
        }

        $this->lastJobPollScanned = $scanned;
        $this->lastJobPollAt      = $now;

        $cancelled = $this->buildCancelledReconcileResult(
            $jobId,
            $onlySheet,
            $sheetCount,
            $scanned,
            $added,
            $updated,
            $removed,
            $unchanged,
            $ignored,
            $errors,
            $discoveredSheets,
        );

        if ($cancelled !== null) {
            return $cancelled;
        }

        $this->tickReconcileProgress(
            $jobId,
            $scanned,
            $grandTotal,
            $entry,
            $added,
            $updated,
            $removed,
            $unchanged,
            $ignored,
        );

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function tickReconcileProgress(
        ?int $jobId,
        int $scanned,
        int $grandTotal,
        array $entry,
        int $added,
        int $updated,
        int $removed,
        int $unchanged,
        int $ignored,
    ): void {
        if ($jobId === null) {
            return;
        }

        service('inventoryImportJob')->updateProgress(
            $jobId,
            $scanned,
            max($grandTotal, 1),
            (string) $entry['sheet_name'],
            sprintf(
                'Reconciled %d of %d — added %d, updated %d, removed %d, unchanged %d, skipped (new, not in Net32) %d.',
                $scanned,
                $grandTotal,
                $added,
                $updated,
                $removed,
                $unchanged,
                $ignored,
            ),
            [
                'added'     => $added,
                'updated'   => $updated,
                'removed'   => $removed,
                'unchanged' => $unchanged,
                'ignored'   => $ignored,
            ],
        );
    }

    /**
     * @param list<string> $errors
     * @param list<string> $discoveredSheets
     *
     * @return array<string, mixed>|null
     */
    private function buildCancelledReconcileResult(
        ?int $jobId,
        ?string $onlySheet,
        int $sheetCount,
        int $scanned,
        int $added,
        int $updated,
        int $removed,
        int $unchanged,
        int $ignored,
        array $errors,
        array $discoveredSheets,
    ): ?array {
        if ($jobId === null || ! service('inventoryImportJob')->isCancelRequested($jobId)) {
            return null;
        }

        return [
            'sheet_name'        => $onlySheet,
            'sheets'            => $sheetCount,
            'scanned'           => $scanned,
            'added'             => $added,
            'updated'           => $updated,
            'removed'           => $removed,
            'unchanged'         => $unchanged,
            'ignored'           => $ignored,
            'errors'            => $errors,
            'discovered_sheets' => $discoveredSheets,
            'sheet_names'       => $this->sheets->getSheetNameOptions(),
            'cancelled'         => true,
            'total'             => max($scanned, 0),
        ];
    }

    private function progressLine(string $message, string $color = 'white'): void
    {
        if (! $this->showProgress) {
            return;
        }

        CLI::write($message, $color);
    }

    private function sleepBetweenRequests(float $delaySeconds): void
    {
        if ($delaySeconds <= 0) {
            return;
        }

        usleep((int) round($delaySeconds * 1_000_000));
    }
}
