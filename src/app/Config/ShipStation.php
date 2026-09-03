<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

class ShipStation extends BaseConfig
{
    /**
     * ShipStation V2 API base URL.
     *
     * @see https://docs.shipstation.com/
     */
    public string $baseURL = 'https://api.shipstation.com/v2';

    /**
     * ShipStation V2 API key from Settings → API Settings.
     */
    public string $apiKey = '';

    /**
     * Default warehouse ID (e.g. se-251394). Leave empty to accept any warehouse.
     */
    public string $warehouseId = '';

    /**
     * Quantity to set when creating or fixing inventory at a bin location.
     */
    public int $defaultPushQuantity = 9999999;

    /**
     * Request timeout in seconds.
     */
    public int $timeout = 30;

    /**
     * Seconds to wait between ShipStation API calls in bulk commands.
     */
    public float $requestDelaySeconds = 1.0;

    /**
     * Header name used by ShipStation V2.
     */
    public string $apiKeyHeader = 'api-key';
}
