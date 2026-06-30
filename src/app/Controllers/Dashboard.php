<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityLogModel;
use App\Models\InventoryModel;

class Dashboard extends BaseController
{
    public function index()
    {
        $inventory = model(InventoryModel::class);
        $logs      = model(ActivityLogModel::class);

        return view('dashboard/index', [
            'title'            => 'Dashboard',
            'totalRows'        => $inventory->countAllResults(),
            'zeroQuantityCount'=> $inventory->countZeroQuantity(),
            'lastSyncedAt'     => $inventory->getLastSyncedAt(),
            'net32Stats'       => $inventory->getNet32Stats(),
            'lastNet32Check'   => $inventory->getLastNet32CheckedAt(),
            'sheetCount'       => count($inventory->getSheetNames()),
            'recentLogs'       => $logs->getRecent(5),
            'actionLabels'     => $logs->actionLabels(),
        ]);
    }
}
