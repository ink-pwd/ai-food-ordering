<?php

namespace App\Services\Resolvers\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Services\Repositories\CartRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class CartItemForUpdateResolver
{
    public function __construct(
        private CartRepository $carts,
    ) {
    }

    public function resolve(
        Cart $cart,
        int $itemId,
    ): CartItem {
        $item = $this->carts->findItemForCartForUpdate(
            $cart,
            $itemId,
        );

        if ($item === null) {
            throw new NotFoundHttpException('Cart item not found.');
        }

        return $item;
    }
}
