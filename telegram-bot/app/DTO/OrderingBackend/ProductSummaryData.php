<?php

namespace App\DTO\OrderingBackend;

final readonly class ProductSummaryData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $price,
        public ?string $promotionPrice,
        public string $currency,
    ) {
    }
}
