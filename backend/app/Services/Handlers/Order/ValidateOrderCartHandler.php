<?php

namespace App\Services\Handlers\Order;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ValidateOrderCartHandler
{
    public function handle(Cart $cart): void
    {
        if ($cart->status !== CartStatus::Active) {
            throw new ConflictHttpException(
                'Cart is not active.',
            );
        }

        /** @var CarbonInterface $expiresAt */
        $expiresAt = $cart->expires_at;

        if ($expiresAt->isPast()) {
            throw ValidationException::withMessages([
                'cart' => [
                    'Cart has expired.',
                ],
            ]);
        }

        /** @var Collection<int, CartItem> $items */
        $items = $cart->items;

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => [
                    'Cart is empty.',
                ],
            ]);
        }

        foreach ($items as $item) {
            /** @var Product|null $product */
            $product = $item->product;

            if (
                $product === null
                || ! $product->is_available
            ) {
                throw ValidationException::withMessages([
                    'cart' => [
                        "Product {$item->external_product_id} is unavailable.",
                    ],
                ]);
            }
        }
    }
}
