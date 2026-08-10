<?php

namespace App\Services\Handlers\Cart;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\RestaurantRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ClearCartHandler
{
    public function __construct(
        private readonly RestaurantRepository $restaurants,
        private readonly CartRepository $carts,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     */
    public function handle(array $session): Cart
    {
        $restaurant = $this->restaurants->findActiveById(
            $session['restaurant_id'],
        );

        if ($restaurant === null) {
            throw new NotFoundHttpException;
        }

        return DB::transaction(function () use (
            $restaurant,
            $session,
        ): Cart {
            $cart = $this->carts->findForSessionForUpdate(
                $restaurant,
                $session['id'],
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

            $this->carts->deleteItems($cart);

            $this->carts->updateTotals(
                $cart,
                '0.00',
                '0.00',
            );

            return $this->carts->refreshWithItems($cart);
        });
    }
}
