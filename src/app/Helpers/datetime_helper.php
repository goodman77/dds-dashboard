<?php

declare(strict_types=1);

use CodeIgniter\I18n\Time;

if (! function_exists('format_log_datetime')) {
    /**
     * Format a stored datetime for log display in Pacific (Los Angeles) time.
     */
    function format_log_datetime(?string $datetime, string $format = 'm/d/Y H:i:s T'): string
    {
        if ($datetime === null || trim($datetime) === '') {
            return '—';
        }

        return Time::parse($datetime, 'UTC')
            ->setTimezone('America/Los_Angeles')
            ->format($format);
    }
}
