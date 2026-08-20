<?php

namespace App\DTO\OrderingBackend;

final readonly class CartItemData
{
    public function __construct(
        public int $id,
        public int $productId,
        public string $externalProductId,
        public string $name,
        public int $quantity,
        public string $unitPrice,
        public string $total,
    ) {
    }
}
