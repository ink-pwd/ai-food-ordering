<?php

namespace App\Services\Handlers\Cart;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\ProductRepository;
use App\Services\Repositories\RestaurantRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AddCartItemHandler
{
    public function __construct(
        private readonly RestaurantRepository $restaurants,
        private readonly CartRepository $carts,
        private readonly ProductRepository $products,
    ) {}

    /**
     * @param  array{id: string, restaurant_id: int, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}  $session
     */
    public function handle(array $session, int $productId, int $quantity): Cart
    {
        $restaurant = $this->restaurants->findActiveById($session['restaurant_id']);

        if ($restaurant === null) {
            throw new NotFoundHttpException;
        }

        return DB::transaction(function () use ($restaurant, $session, $productId, $quantity): Cart {
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

            $product = $this->products->findForRestaurantById(
                $restaurant,
                $productId,
            );

            if ($product === null || ! $product->is_available) {
                throw new NotFoundHttpException('Product not found.');
            }

            $unitPrice = $product->promotion_price ?? $product->price;
            $lineTotal = $this->multiplyMoney($unitPrice, $quantity);

            $result = $this->carts->createItem(
                $cart,
                $product,
                $quantity,
                $unitPrice,
                $lineTotal,
            );

            if (! $result['created']) {
                throw new ConflictHttpException('Product already exists in cart.');
            }

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

    private function multiplyMoney(string $amount, int $quantity): string
    {
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
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

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
