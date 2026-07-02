<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

class GoogleSheets extends BaseConfig
{
    /**
     * Bradley BINS spreadsheet ID from the Google Sheets URL.
     */
    public string $spreadsheetId = '16-5ignJPXAhw07JXOi_JdIlIBdHtPsbpFfV5-xrNW-I';

    /**
     * Optional Google API key for listing worksheet tabs via Sheets API v4.
     * The sheet must be shared as "Anyone with the link can view" (or use gviz fallback).
     */
    public string $apiKey = '';

    /**
     * Comma-separated worksheet tab names when no API key is configured.
     * Defaults to tabs 1–36, M1–M22, and XM99 from the Bradley BINS workbook.
     */
    public string $sheetNames = '1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,M1,M2,M3,M4,M5,M6,M7,M8,M9,M10,M11,M12,M13,M14,M15,M16,M17,M18,M19,M20,M21,M22,X,XM99';

    public int $timeout = 30;
}
