<?php

declare(strict_types=1);

namespace App\Libraries\Net32\Resources;

use App\Libraries\Net32\Exceptions\Net32ApiException;
use App\Libraries\Net32\Net32Client;
use Config\Net32 as Net32Config;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;

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
        return $this->normalizeOffersResponse($this->client->get('products/offers', $query));
    }

    public function getOffersAsync(array $query = []): PromiseInterface
    {
        return $this->client->getAsync('products/offers', $query)->then(
            fn (?array $response): array => $this->normalizeOffersResponse($response),
            function ($reason) {
                if ($reason instanceof Net32ApiException && $reason->getStatusCode() === 404) {
                    return $this->normalizeOffersResponse(null);
                }

                throw $reason instanceof \Throwable
                    ? $reason
                    : new Net32ApiException((string) $reason);
            },
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, int>
     */
    public function inventoryMapFromItems(array $items): array
    {
        $map = [];

        foreach ($items as $item) {
            $code = strtolower(trim((string) ($item['vpCode'] ?? '')));

            if ($code === '') {
                continue;
            }

            $map[$code] = max(0, (int) ($item['inventory'] ?? 0));
        }

        return $map;
    }

    /**
     * Check whether a vendor product code exists in Net32.
     */
    public function existsByVpCode(string $vpCode): bool
    {
        return $this->findOfferByVpCode($vpCode) !== null;
    }

    /**
     * Look up an offer by vendor product code.
     *
     * Net32's vpCode query is case-sensitive, while Google Sheets / inventory
     * often use mixed case (hb3116 vs HB3116). Tries common case variants.
     *
     * @return array<string, mixed>|null
     */
    public function findOfferByVpCode(string $vpCode): ?array
    {
        foreach ($this->vpCodeLookupVariants($vpCode) as $variant) {
            $offer = $this->findOfferByVpCodeExact($variant);

            if ($offer !== null) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * Look up one SKU without blocking the caller. Use with Guzzle Promise\Utils::settle().
     */
    public function findOfferByVpCodeAsync(string $vpCode): PromiseInterface
    {
        $variants = $this->vpCodeLookupVariants($vpCode);

        if ($variants === []) {
            return Create::promiseFor(null);
        }

        $tryNext = null;
        $tryNext = function (int $index) use (&$tryNext, $variants): PromiseInterface {
            if (! isset($variants[$index])) {
                return Create::promiseFor(null);
            }

            return $this->findOfferByVpCodeExactAsync($variants[$index])->then(
                static function (?array $offer) use (&$tryNext, $index): PromiseInterface|array|null {
                    if ($offer !== null) {
                        return $offer;
                    }

                    return $tryNext($index + 1);
                },
            );
        };

        return $tryNext(0);
    }

    /**
     * Case variants to try against Net32's case-sensitive vpCode search.
     *
     * @return list<string>
     */
    private function vpCodeLookupVariants(string $vpCode): array
    {
        $vpCode = trim($vpCode);

        if ($vpCode === '') {
            return [];
        }

        // Prefer UPPER first — Net32 catalogs are typically uppercase, so mixed-case
        // sheet SKUs usually resolve in one request.
        $variants = [strtoupper($vpCode), $vpCode, strtolower($vpCode)];

        return array_values(array_unique($variants));
    }

    private function findOfferByVpCodeExact(string $vpCode): ?array
    {
        try {
            $pageData = $this->getOffers(['vpCode' => $vpCode]);
        } catch (Net32ApiException $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }

        return $this->firstOfferByVpCode($pageData['items'], $vpCode);
    }

    private function findOfferByVpCodeExactAsync(string $vpCode): PromiseInterface
    {
        return $this->client->getAsync('products/offers', ['vpCode' => $vpCode])->then(
            fn (?array $response): ?array => $this->firstOfferByVpCode(
                $this->normalizeOffersResponse($response)['items'],
                $vpCode,
            ),
            function ($reason) {
                if ($reason instanceof Net32ApiException && $reason->getStatusCode() === 404) {
                    return null;
                }

                throw $reason instanceof \Throwable
                    ? $reason
                    : new Net32ApiException((string) $reason);
            },
        );
    }

    /**
     * Fetch all offer pages when the API supports page/pageSize query params.
     *
     * @param null|callable(int $page, array{items: list<array<string, mixed>>, totalResults: int, totalReturned: int} $pageData, int $collected): bool $onPage
     *
     * @return array{items: list<array<string, mixed>>, totalResults: int, pagesFetched: int, cancelled: bool}
     */
    public function getAllOffers(int $pageSize = 200, ?callable $onPage = null, ?float $delaySeconds = null): array
    {
        $allItems     = [];
        $totalResults = 0;
        $page         = 1;
        $cancelled    = false;
        $delaySeconds = max(0.0, $delaySeconds ?? (float) $this->config->requestDelaySeconds);

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

            if ($onPage !== null && $onPage($page, $pageData, count($allItems)) === false) {
                $cancelled = true;
                break;
            }

            $page++;

            if ($pageData['totalReturned'] < $pageSize) {
                break;
            }

            if ($delaySeconds > 0 && $page <= 100 && count($allItems) < $totalResults) {
                usleep((int) round($delaySeconds * 1_000_000));
            }
        } while (! $cancelled && $page <= 100 && count($allItems) < $totalResults);

        return [
            'items'         => $allItems,
            'totalResults'  => $totalResults,
            'pagesFetched'  => max(0, $page - 1),
            'cancelled'     => $cancelled,
        ];
    }

    /**
     * @param null|callable(int $page, array{items: list<array<string, mixed>>, totalResults: int, totalReturned: int} $pageData, int $collected): bool $onPage
     *
     * @return array{map: array<string, int>, pagesFetched: int, totalResults: int, cancelled: bool}
     */
    public function inventoryByVpCode(int $pageSize = 200, ?callable $onPage = null, ?float $delaySeconds = null): array
    {
        $pageResult = $this->getAllOffers($pageSize, $onPage, $delaySeconds);

        return [
            'map'          => $this->inventoryMapFromItems($pageResult['items']),
            'pagesFetched' => $pageResult['pagesFetched'],
            'totalResults' => $pageResult['totalResults'],
            'cancelled'    => $pageResult['cancelled'],
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

        // Prefer the canonical casing Net32 stores (lookup is case-insensitive).
        $offer = $this->findOfferByVpCode($vpCode);
        $canonical = is_array($offer) && trim((string) ($offer['vpCode'] ?? '')) !== ''
            ? trim((string) $offer['vpCode'])
            : strtoupper($vpCode);

        $response = $this->client->request('POST', 'products/offers/update', [
            'json' => [
                'vpCode'    => $canonical,
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

    /**
     * @param array<string, mixed>|list<mixed>|null $response
     *
     * @return array{items: list<array<string, mixed>>, totalResults: int, totalReturned: int}
     */
    private function normalizeOffersResponse(?array $response): array
    {
        if ($response === null) {
            return [
                'items'         => [],
                'totalResults'  => 0,
                'totalReturned' => 0,
            ];
        }

        $payload = $response['payload'] ?? $response;
        $items   = is_array($payload)
            ? ($payload['result'] ?? $payload['results'] ?? $payload['offers'] ?? [])
            : [];

        if (! is_array($items)) {
            $items = [];
        }

        /** @var list<array<string, mixed>> $items */
        return [
            'items'         => $items,
            'totalResults'  => (int) (is_array($payload) ? ($payload['totalResults'] ?? count($items)) : count($items)),
            'totalReturned' => (int) (is_array($payload) ? ($payload['totalReturned'] ?? count($items)) : count($items)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>|null
     */
    private function firstOfferByVpCode(array $items, string $vpCode): ?array
    {
        foreach ($items as $item) {
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
}
