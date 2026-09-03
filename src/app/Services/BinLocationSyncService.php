<?php

declare(strict_types=1);

namespace App\Services;

class BinLocationSyncService
{
    public function __construct(
        private readonly InventoryReconcileFromSheetsService $reconcile,
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
        $result = $this->reconcile->reconcileFromGoogleSheets();

        return [
            'imported' => $result['added'],
            'updated'  => $result['updated'],
            'removed'  => $result['removed'],
            'sheets'   => $result['sheets'],
            'scanned'  => $result['scanned'],
            'skipped'  => $result['unchanged'],
            'ignored'  => $result['ignored'],
            'errors'   => $result['errors'],
        ];
    }
}
