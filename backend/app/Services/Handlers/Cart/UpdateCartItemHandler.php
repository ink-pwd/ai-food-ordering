<?php

namespace App\Services\Handlers\Cart;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\ProductRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Support\SessionSelection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateCartItemHandler
{
    public function __construct(
        private readonly RestaurantRepository $restaurants,
        private readonly CartRepository $carts,
        private readonly ProductRepository $products,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     */
    public function handle(
        array $session,
        int $itemId,
        int $quantity,
    ): Cart {
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
            $quantity,
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

            $product = $this->products->findForRestaurantById(
                $restaurant,
                $item->product_id,
            );

            if ($product === null || ! $product->is_available) {
                throw new NotFoundHttpException('Product not found.');
            }

            $unitPrice = $product->promotion_price ?? $product->price;

            $lineTotal = $this->multiplyMoney(
                $unitPrice,
                $quantity,
            );

            $this->carts->updateItem(
                $item,
                $quantity,
                $unitPrice,
                $lineTotal,
            );

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

    private function multiplyMoney(
        string $amount,
        int $quantity,
    ): string {
        return $this->fromCents(
            $this->toCents($amount) * $quantity,
        );
    }

    /**
     * @param  array<int, string>  $amounts
     */
    private function sumMoney(array $amounts): string
    {
        $cents = 0;

        foreach ($amounts as $amount) {
            $cents += $this->toCents($amount);
        }

        return $this->fromCents($cents);
    }

    private function toCents(string $amount): int
    {
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

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function fromCents(int $cents): string
    {
        return sprintf(
            '%d.%02d',
            intdiv($cents, 100),
            $cents % 100,
        );
    }
}
