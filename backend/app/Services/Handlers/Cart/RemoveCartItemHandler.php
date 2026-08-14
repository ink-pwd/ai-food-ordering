<?php

namespace App\Services\Handlers\Cart;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Support\SessionSelection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RemoveCartItemHandler
{
    public function __construct(
        private readonly RestaurantRepository $restaurants,
        private readonly CartRepository $carts,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     */
    public function handle(array $session, int $itemId): Cart
    {
        $restaurant = $this->restaurants->findActiveById(
            SessionSelection::restaurantId($session),
        );

        if ($restaurant === null) {
            throw new NotFoundHttpException;
        }

        return DB::transaction(function () use (
            $restaurant,
            $session,
            $itemId,
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

            $item = $this->carts->findItemForCartForUpdate(
                $cart,
                $itemId,
            );

            if ($item === null) {
                throw new NotFoundHttpException('Cart item not found.');
            }

            $this->carts->deleteItem($item);

            $subtotal = $this->sumMoney(
                $this->carts->itemTotals($cart),
            );

            $this->carts->updateTotals(
                $cart,
                $subtotal,
                $subtotal,
            );

            return $this->carts->refreshWithItems($cart);
        });
    }

    /**
     * @param  array<int, string>  $amounts
     */
    private function sumMoney(array $amounts): string
    {
        $cents = 0;

        foreach ($amounts as $amount) {
            [$whole, $fraction] = array_pad(
                explode('.', $amount, 2),
                2,
                '0',
            );

            $fraction = str_pad(
                substr($fraction, 0, 2),
                2,
                '0',
            );

            $cents += ((int) $whole * 100) + (int) $fraction;
        }

        return sprintf(
            '%d.%02d',
            intdiv($cents, 100),
            $cents % 100,
        );
    }
}
