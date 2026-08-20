<?php

namespace App\DTO\OrderingBackend;

final readonly class OrderItemData
{
    public function __construct(
        public ?int $productId,
        public string $externalProductId,
        public string $name,
        public int $quantity,
        public string $unitPrice,
        public string $total,
    ) {
    }
}
