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
     * Inventory warehouse to treat as current.
     * Use "latest" to pick the newest warehouse from ShipStation (by created_at).
     * Or set a fixed ID such as se-257006.
     */
    public string $warehouseId = 'latest';

    /**
     * Quantity to set when creating or fixing inventory at a bin location.
     */
    public int $defaultPushQuantity = 9999999;

    /**
     * Request timeout in seconds.
     */
    public int $timeout = 30;

    /**
     * Seconds to wait after a ShipStation write (move or create bin) in bulk sync.
     * Missing and already-correct SKUs skip this delay.
     */
    public float $requestDelaySeconds = 1.0;

    /**
     * Header name used by ShipStation V2.
     */
    public string $apiKeyHeader = 'api-key';
}
