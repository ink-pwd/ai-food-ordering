<?php

namespace App\DTO\Ai;

use App\DTO\OrderingBackend\CartData;
use App\DTO\OrderingBackend\CartItemData;

final readonly class AiCartData
{
    /** @param list<AiCartItemData> $items */
    public function __construct(
        public string $currency,
        public string $total,
        public array $items,
    ) {
    }

    public static function fromCart(CartData $cart): self
    {
        return new self(
            currency: $cart->currency,
            total: $cart->total,
            items: array_map(
                static fn (CartItemData $item): AiCartItemData => AiCartItemData::fromCartItem($item),
                $cart->items,
            ),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'total' => $this->total,
            'items' => array_map(
                static fn (AiCartItemData $item): array => $item->toArray(),
                $this->items,
            ),
        ];
    }
}
