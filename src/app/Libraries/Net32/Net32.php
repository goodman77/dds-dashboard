<?php

declare(strict_types=1);

namespace App\Libraries\Net32;

use App\Libraries\Net32\Resources\AccountResource;
use App\Libraries\Net32\Resources\OrdersResource;
use App\Libraries\Net32\Resources\ProductsResource;

/**
 * Net32 Vendor API client facade.
 */
class Net32
{
    private ?OrdersResource $orders = null;

    private ?AccountResource $account = null;

    private ?ProductsResource $products = null;

    public function __construct(
        private readonly Net32Client $client,
        private readonly \Config\Net32 $config,
    ) {
    }

    public function orders(): OrdersResource
    {
        return $this->orders ??= new OrdersResource($this->client);
    }

    public function account(): AccountResource
    {
        return $this->account ??= new AccountResource($this->client);
    }

    public function products(): ProductsResource
    {
        return $this->products ??= new ProductsResource($this->client, $this->config);
    }

    /**
     * @param array<string, mixed> $options Guzzle request options
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function request(string $method, string $uri, array $options = []): array|null
    {
        return $this->client->request($method, $uri, $options);
    }
}
