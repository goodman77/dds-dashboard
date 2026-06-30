<?php

declare(strict_types=1);

namespace App\Libraries\Net32\Resources;

use App\Libraries\Net32\Net32Client;

/**
 * Net32 Account API endpoints.
 */
class AccountResource
{
    public function __construct(private readonly Net32Client $client)
    {
    }

    /**
     * GET /account/points-of-shipping
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function getPointsOfShipping(): array|null
    {
        return $this->client->get('account/points-of-shipping');
    }

    /**
     * Call any account endpoint not yet wrapped here.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function request(string $method, string $uri, array $options = []): array|null
    {
        return $this->client->request($method, $uri, $options);
    }
}
