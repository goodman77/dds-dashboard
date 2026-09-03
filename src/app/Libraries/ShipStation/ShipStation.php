<?php

declare(strict_types=1);

namespace App\Libraries\ShipStation;

use App\Libraries\ShipStation\Resources\InventoryResource;

/**
 * ShipStation V2 API client facade.
 */
class ShipStation
{
    private ?InventoryResource $inventory = null;

    public function __construct(
        private readonly ShipStationClient $client,
        private readonly \Config\ShipStation $config,
    ) {
    }

    public function inventory(): InventoryResource
    {
        return $this->inventory ??= new InventoryResource($this->client, $this->config);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function request(string $method, string $uri, array $options = []): array|null
    {
        return $this->client->request($method, $uri, $options);
    }
}
