<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class SheetsSyncBins extends BaseCommand
{
    protected $group       = 'Sheets';
    protected $name        = 'sheets:sync-bins';
    protected $description = 'Sync rack/bin/SKU data from the configured Google Sheet.';
    protected $usage       = 'sheets:sync-bins';

    public function run(array $params)
    {
        $result = service('binLocationSync')->syncFromGoogleSheet();

        CLI::write(sprintf(
            'Sheets: %d | Scanned: %d | Imported: %d | Already in DB: %d | Not in Net32: %d',
            $result['sheets'],
            $result['scanned'] ?? 0,
            $result['imported'],
            $result['skipped'] ?? 0,
            $result['ignored'] ?? 0,
        ), 'green');

        foreach ($result['errors'] as $error) {
            CLI::write($error, 'yellow');
        }
    }
}
