<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Net32 extends BaseConfig
{
    /**
     * Net32 Vendor API base URL.
     *
     * @see https://portal.api.net32.com/
     */
    public string $baseURL = 'https://api.net32.com';

    /**
     * Azure APIM subscription key from the Net32 Vendor API portal.
     */
    public string $subscriptionKey = '';

    /**
     * Request timeout in seconds.
     */
    public int $timeout = 30;

    /**
     * Seconds to wait between Net32 API calls in bulk import/check commands.
     */
    public float $requestDelaySeconds = 2.0;

    /**
     * Seconds to wait before retrying after a 429 rate-limit response.
     */
    public float $rateLimitRetryDelaySeconds = 3.5;

    /**
     * Maximum number of retries after a 429 response for a single request.
     */
    public int $rateLimitMaxRetries = 30;

    /**
     * Header name used by Net32 Azure APIM.
     */
    public string $subscriptionHeader = 'Subscription-Key';
}
