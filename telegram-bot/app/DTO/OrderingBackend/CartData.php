<?php

namespace App\DTO\OrderingBackend;

final readonly class CartData
{
    /**
     * @param  list<CartItemData>  $items
     */
    public function __construct(
        public int $id,
        public string $status,
        public string $currency,
        public string $subtotal,
        public string $total,
        public string $expiresAt,
        public array $items,
    ) {
    }
}
