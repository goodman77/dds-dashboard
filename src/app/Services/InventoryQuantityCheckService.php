<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\Net32\Exceptions\Net32ApiException;
use App\Libraries\Net32\Resources\ProductsResource;
use App\Models\InventoryModel;

class InventoryQuantityCheckService
{
    public function __construct(
        private readonly InventoryModel $inventory,
        private readonly ProductsResource $products,
    ) {
    }

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     quantity?: int,
     *     previous_quantity?: int,
     *     sku_net32_exists?: bool|null
     * }
     */
    public function checkRow(int $id): array
    {
        $row = $this->inventory->find($id);

        if ($row === null) {
            return ['ok' => false, 'message' => 'Inventory row not found.'];
        }

        $sku = trim((string) ($row['sku'] ?? ''));

        if ($sku === '') {
            return ['ok' => false, 'message' => 'This row has no SKU to check.'];
        }

        $checkedAt = date('Y-m-d H:i:s');
        $previousQuantity = (int) ($row['quantity'] ?? 0);

        try {
            $offer = $this->products->findOfferByVpCode($sku);
        } catch (Net32ApiException $exception) {
            $this->inventory->update($id, [
                'sku_net32_exists' => 0,
                'net32_checked_at' => $checkedAt,
            ]);

            return [
                'ok'               => false,
                'message'          => $exception->getMessage(),
                'previous_quantity'=> $previousQuantity,
                'quantity'         => $previousQuantity,
                'sku_net32_exists' => false,
            ];
        }

        if ($offer === null) {
            $this->inventory->update($id, [
                'sku_net32_exists' => 0,
                'net32_checked_at' => $checkedAt,
            ]);

            $message = sprintf('SKU %s was not found in Net32.', $sku);

            return [
                'ok'               => false,
                'message'          => $message,
                'previous_quantity'=> $previousQuantity,
                'quantity'         => $previousQuantity,
                'sku_net32_exists' => false,
            ];
        }

        $quantity = max(0, (int) ($offer['quantity'] ?? 0));

        if (! $this->inventory->update($id, [
            'quantity'         => $quantity,
            'sku_net32_exists' => 1,
            'net32_checked_at' => $checkedAt,
        ])) {
            return ['ok' => false, 'message' => 'Could not save the updated quantity.'];
        }

        $message = $quantity === $previousQuantity
            ? sprintf('Quantity confirmed at %d in Net32.', $quantity)
            : sprintf('Quantity updated from %s to %s.', number_format($previousQuantity), number_format($quantity));

        return [
            'ok'                => true,
            'message'           => $message,
            'quantity'          => $quantity,
            'previous_quantity' => $previousQuantity,
            'sku_net32_exists'  => true,
        ];
    }

    /**
     * @return array{exists: bool|null, warning: string|null}
     */
    public function inspectSkuInNet32(string $sku): array
    {
        $sku = trim($sku);

        if ($sku === '') {
            return ['exists' => null, 'warning' => null];
        }

        try {
            $offer = $this->products->findOfferByVpCode($sku);
        } catch (Net32ApiException $exception) {
            return [
                'exists'  => null,
                'warning' => sprintf('Could not verify SKU %s in Net32: %s', $sku, $exception->getMessage()),
            ];
        }

        if ($offer === null) {
            return [
                'exists'  => false,
                'warning' => sprintf('Warning: SKU %s was not found in Net32.', $sku),
            ];
        }

        return ['exists' => true, 'warning' => null];
    }

    /**
     * Push a quantity change to Net32 for a local inventory row.
     *
     * @return array{ok: bool, message: string}
     */
    public function pushQuantityToNet32(string $sku, int $quantity): array
    {
        $sku = trim($sku);

        if ($sku === '') {
            return ['ok' => false, 'message' => 'This row has no SKU to sync to Net32.'];
        }

        $quantity = max(0, $quantity);

        try {
            $this->products->updateInventoryByVpCode($sku, $quantity);
        } catch (Net32ApiException $exception) {
            return [
                'ok'      => false,
                'message' => $exception->getMessage(),
            ];
        }

        return [
            'ok'      => true,
            'message' => sprintf('Quantity synced to Net32 for SKU %s.', $sku),
        ];
    }
}
