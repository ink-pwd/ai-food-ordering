<?php

namespace App\Services\Resolvers\Cart;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\Restaurant;
use App\Services\Repositories\CartRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ActiveCartForUpdateResolver
{
    public function __construct(
        private CartRepository $carts,
    ) {
    }

    public function resolve(
        Restaurant $restaurant,
        string $sessionId,
    ): Cart {
        $cart = $this->carts->findForSessionForUpdate(
            $restaurant,
            $sessionId,
        );

        if ($cart === null) {
            throw new NotFoundHttpException('Cart not found.');
        }

        if (
            $cart->status !== CartStatus::Active
            || $cart->expires_at->lessThanOrEqualTo(now())
        ) {
            throw new ConflictHttpException('Cart is not active.');
        }

        return $cart;
    }
}
