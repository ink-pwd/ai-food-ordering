<?php

namespace App\DTO\Ai;

use App\DTO\OrderingBackend\ProductSummaryData;

final readonly class AiProductSearchData
{
    /** @param list<AiProductData> $products */
    public function __construct(
        public string $query,
        public array $products,
    ) {
    }

    /**
     * @param  list<ProductSummaryData>  $products
     */
    public static function fromProducts(
        string $query,
        array $products,
    ): self {
        return new self(
            query: $query,
            products: array_map(
                static fn (ProductSummaryData $product): AiProductData => AiProductData::fromProduct($product),
                $products,
            ),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'products' => array_map(
                static fn (AiProductData $product): array => $product->toArray(),
                $this->products,
            ),
        ];
    }
}
