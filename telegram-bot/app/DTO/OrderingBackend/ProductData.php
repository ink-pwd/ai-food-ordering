<?php

namespace App\DTO\OrderingBackend;

final readonly class ProductData
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public string $price,
        public ?string $promotionPrice,
        public string $currency,
        public bool $isAvailable,
    ) {
    }
}
