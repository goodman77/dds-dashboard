<?php

declare(strict_types=1);

namespace App\Libraries\Net32\Resources;

use App\Libraries\Net32\Net32Client;

/**
 * Net32 Order API endpoints.
 *
 * @see https://support.net32.com/hc/en-us/articles/1500008087762-Order-API-Endpoints
 */
class OrdersResource
{
    public function __construct(private readonly Net32Client $client)
    {
    }

    /**
     * GET /orders/
     *
     * Returns up to 100 pending, unviewed orders.
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function getPending(): array|null
    {
        return $this->client->get('orders/');
    }

    /**
     * GET /pre-auth/{orderId}
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function preAuthorize(int|string $orderId): array|null
    {
        return $this->client->get("pre-auth/{$orderId}");
    }

    /**
     * POST /orders/{orderId}/charges/
     *
     * @param array<string, mixed>|list<mixed> $payload
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function ship(int|string $orderId, array $payload): array|null
    {
        return $this->client->post("orders/{$orderId}/charges/", $payload);
    }

    /**
     * GET /orders/{orderId}/charges/{chargeRequestId}
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function getChargeStatus(int|string $orderId, int|string $chargeRequestId): array|null
    {
        return $this->client->get("orders/{$orderId}/charges/{$chargeRequestId}");
    }
}
