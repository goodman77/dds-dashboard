<?php

declare(strict_types=1);

namespace App\Libraries\Net32\Resources;

use App\Libraries\Net32\Net32Client;
use Config\Net32 as Net32Config;
use GuzzleHttp\Client;

/**
 * Net32 product catalog helpers.
 *
 * @see https://portal.api.net32.com/products
 */
class ProductsResource
{
    public function __construct(
        private readonly Net32Client $client,
        private readonly Net32Config $config,
    ) {
    }

    /**
     * Returns vendor product offers. Net32 paginates results (typically 200 per page).
     *
     * @param array<string, scalar|null> $query
     *
     * @return array{items: list<array<string, mixed>>, totalResults: int, totalReturned: int}
     */
    public function getOffers(array $query = []): array
    {
        $response = $this->client->get('products/offers', $query);

        if ($response === null) {
            return [
                'items'          => [],
                'totalResults'   => 0,
                'totalReturned'  => 0,
            ];
        }

        $payload = $response['payload'] ?? $response;
        $items     = $payload['result'] ?? $payload['results'] ?? $payload['offers'] ?? [];

        if (! is_array($items)) {
            $items = [];
        }

        /** @var list<array<string, mixed>> $items */
        return [
            'items'         => $items,
            'totalResults'  => (int) ($payload['totalResults'] ?? count($items)),
            'totalReturned' => (int) ($payload['totalReturned'] ?? count($items)),
        ];
    }

    /**
     * Check whether a vendor product code exists in Net32.
     */
    public function existsByVpCode(string $vpCode): bool
    {
        return $this->findOfferByVpCode($vpCode) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOfferByVpCode(string $vpCode): ?array
    {
        $vpCode = trim($vpCode);

        if ($vpCode === '') {
            return null;
        }

        try {
            $pageData = $this->getOffers(['vpCode' => $vpCode]);
        } catch (\App\Libraries\Net32\Exceptions\Net32ApiException $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }

        foreach ($pageData['items'] as $item) {
            if (strcasecmp((string) ($item['vpCode'] ?? ''), $vpCode) !== 0) {
                continue;
            }

            $description = trim((string) ($item['description'] ?? ''));
            $brandName   = trim((string) ($item['brandName'] ?? ''));

            return [
                'vpCode'      => (string) ($item['vpCode'] ?? $vpCode),
                'name'        => $brandName !== '' ? $brandName : ($description !== '' ? $description : $vpCode),
                'description' => $description,
                'quantity'    => max(0, (int) ($item['inventory'] ?? 0)),
            ];
        }

        return null;
    }

    /**
     * Fetch all offer pages when the API supports page/pageSize query params.
     *
     * @return array{items: list<array<string, mixed>>, totalResults: int, pagesFetched: int}
     */
    public function getAllOffers(int $pageSize = 200): array
    {
        $allItems     = [];
        $totalResults = 0;
        $page         = 1;

        do {
            $pageData = $this->getOffers([
                'page'     => $page,
                'pageSize' => $pageSize,
            ]);

            if ($page === 1) {
                $totalResults = $pageData['totalResults'];
            }

            if ($pageData['items'] === []) {
                break;
            }

            $firstMpid = $pageData['items'][0]['mpid'] ?? null;

            if ($page > 1 && $firstMpid !== null && ($allItems[0]['mpid'] ?? null) === $firstMpid) {
                break;
            }

            $allItems = array_merge($allItems, $pageData['items']);
            $page++;

            if ($pageData['totalReturned'] < $pageSize) {
                break;
            }
        } while ($page <= 100 && count($allItems) < $totalResults);

        return [
            'items'         => $allItems,
            'totalResults'  => $totalResults,
            'pagesFetched'  => $page - 1,
        ];
    }

    /**
     * Push inventory quantity for a vendor product offer.
     *
     * @return array<string, mixed>
     */
    public function updateInventoryByVpCode(string $vpCode, int $quantity): array
    {
        $vpCode = trim($vpCode);
        $quantity = max(0, $quantity);

        if ($vpCode === '') {
            throw new \InvalidArgumentException('vpCode is required.');
        }

        $response = $this->client->request('POST', 'products/offers/update', [
            'json' => [
                'vpCode'    => $vpCode,
                'inventory' => $quantity,
            ],
        ]);

        if ($response === null) {
            throw new \App\Libraries\Net32\Exceptions\Net32ApiException('Empty response from Net32.', 502);
        }

        $payload = $response['payload'] ?? $response;

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    public function request(string $method, string $uri, array $options = []): array|null
    {
        return $this->client->request($method, $uri, $options);
    }
}
