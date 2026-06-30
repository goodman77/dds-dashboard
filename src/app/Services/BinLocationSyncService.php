<?php

declare(strict_types=1);

namespace App\Services;

class BinLocationSyncService
{
    public function __construct(
        private readonly InventoryImportService $import,
    ) {
    }

    /**
     * @return array{
     *     imported: int,
     *     updated: int,
     *     removed: int,
     *     sheets: int,
     *     scanned: int,
     *     skipped: int,
     *     ignored: int,
     *     errors: list<string>
     * }
     */
    public function syncFromGoogleSheet(): array
    {
        $result = $this->import->importFromGoogleSheets();

        return [
            'imported' => $result['imported'],
            'updated'  => 0,
            'removed'  => 0,
            'sheets'   => $result['sheets'],
            'scanned'  => $result['scanned'],
            'skipped'  => $result['skipped'],
            'ignored'  => $result['ignored'],
            'errors'   => $result['errors'],
        ];
    }
}
