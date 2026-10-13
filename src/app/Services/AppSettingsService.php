<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSettingModel;

class AppSettingsService
{
    public const LOW_STOCK_THRESHOLD_KEY = 'net32_low_stock_threshold';

    public const DEFAULT_LOW_STOCK_THRESHOLD = 10;

    public function __construct(
        private readonly AppSettingModel $settings,
    ) {
    }

    public function lowStockThreshold(): int
    {
        $raw = $this->settings->getValue(
            self::LOW_STOCK_THRESHOLD_KEY,
            (string) self::DEFAULT_LOW_STOCK_THRESHOLD,
        );

        return max(0, (int) $raw);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, message: string, threshold?: int}
     */
    public function updateLowStockThreshold(array $input): array
    {
        $raw = $input['net32_low_stock_threshold'] ?? null;
        $provided = is_string($raw) ? trim($raw) !== '' : ($raw !== null && $raw !== '');

        if (! $provided) {
            return ['ok' => false, 'message' => 'Enter a low-stock threshold.'];
        }

        if (! is_numeric($raw)) {
            return ['ok' => false, 'message' => 'Threshold must be a whole number of 0 or greater.'];
        }

        $threshold = (int) $raw;

        if ($threshold < 0 || (string) $threshold !== trim((string) $raw)) {
            return ['ok' => false, 'message' => 'Threshold must be a whole number of 0 or greater.'];
        }

        if (! $this->settings->setValue(self::LOW_STOCK_THRESHOLD_KEY, (string) $threshold)) {
            return ['ok' => false, 'message' => 'Could not save settings.'];
        }

        return [
            'ok'        => true,
            'message'   => sprintf('Low-stock threshold saved as %d.', $threshold),
            'threshold' => $threshold,
        ];
    }
}
