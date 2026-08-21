<?php

namespace App\DTO\Ai;

use App\DTO\OrderingBackend\CartItemData;

final readonly class AiCartItemData
{
    public function __construct(
        public int $itemId,
        public int $productId,
        public string $name,
        public int $quantity,
        public string $unitPrice,
        public string $total,
    ) {
    }

    public static function fromCartItem(
        CartItemData $item,
    ): self {
        return new self(
            itemId: $item->id,
            productId: $item->productId,
            name: $item->name,
            quantity: $item->quantity,
            unitPrice: $item->unitPrice,
            total: $item->total,
        );
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'product_id' => $this->productId,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'total' => $this->total,
        ];
    }
}
