<?php

namespace App\Services\Handlers\Cart;

use App\DTO\SessionData;
use App\Models\Cart;
use App\Models\Restaurant;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\ProductRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Resolvers\Cart\ActiveCartForUpdateResolver;
use App\Services\Resolvers\Cart\CartItemForUpdateResolver;
use App\Services\Support\SessionSelection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class UpdateCartItemHandler
{
    public function __construct(
        private RestaurantRepository $restaurants,
        private CartRepository $carts,
        private ProductRepository $products,
        private ActiveCartForUpdateResolver $activeCartForUpdateResolver,
        private CartItemForUpdateResolver $cartItemForUpdateResolver,
    ) {
    }

    /**
     * @throws \Throwable
     */
    public function handle(
        SessionData $session,
        int $itemId,
        int $quantity,
    ): Cart {
        $restaurant = $this->restaurants->findActiveById(
            SessionSelection::restaurantId($session),
        );

        if ($restaurant === null) {
            throw new NotFoundHttpException;
        }

        // The cart/item rows are locked below; item update and total recalculation must commit atomically.
        return DB::transaction(
            fn (): Cart => $this->updateItemAndRecalculateTotals(
                $restaurant,
                $session,
                $itemId,
                $quantity,
            ),
        );
    }

    private function updateItemAndRecalculateTotals(
        Restaurant $restaurant,
        SessionData $session,
        int $itemId,
        int $quantity,
    ): Cart {
        $cart = $this->activeCartForUpdateResolver->resolve(
            $restaurant,
            $session->id,
        );

        $item = $this->cartItemForUpdateResolver->resolve(
            $cart,
            $itemId,
        );

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
