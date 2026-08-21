<?php

namespace App\DTO\Ai;

use App\DTO\OrderingBackend\ProductSummaryData;

final readonly class AiProductData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $price,
        public ?string $promotionPrice,
        public string $currency,
    ) {
    }

    public static function fromProduct(
        ProductSummaryData $product,
    ): self {
        return new self(
            id: $product->id,
            name: $product->name,
            price: $product->price,
            promotionPrice: $product->promotionPrice,
            currency: $product->currency,
        );
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'promotion_price' => $this->promotionPrice,
            'currency' => $this->currency,
        ];
    }
}
