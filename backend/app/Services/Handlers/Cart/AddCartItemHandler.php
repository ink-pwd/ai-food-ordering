<?php

namespace App\Services\Handlers\Cart;

use App\DTO\SessionData;
use App\Models\Cart;
use App\Models\Restaurant;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\ProductRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Resolvers\Cart\ActiveCartForUpdateResolver;
use App\Services\Support\SessionSelection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class AddCartItemHandler
{
    public function __construct(
        private readonly RestaurantRepository $restaurants,
        private readonly CartRepository $carts,
        private readonly ProductRepository $products,
        private readonly ActiveCartForUpdateResolver $activeCartForUpdateResolver,
    ) {
    }

    /**
     * @throws \Throwable
     */
    public function handle(
        SessionData $session,
        int $productId,
        int $quantity,
    ): Cart {
        $restaurant = $this->restaurants->findActiveById(
            SessionSelection::restaurantId($session),
        );

        if ($restaurant === null) {
            throw new NotFoundHttpException;
        }

        // The cart row is locked below; item creation and total recalculation must commit atomically.
        return DB::transaction(
            fn (): Cart => $this->addItemAndRecalculateTotals(
                $restaurant,
                $session,
                $productId,
                $quantity,
            ),
        );
    }

    private function addItemAndRecalculateTotals(
        Restaurant $restaurant,
        SessionData $session,
        int $productId,
        int $quantity,
    ): Cart {
        $cart = $this->activeCartForUpdateResolver->resolve(
            $restaurant,
            $session->id,
        );

        $product = $this->products->findForRestaurantById(
            $restaurant,
            $productId,
        );

        if ($product === null || ! $product->is_available) {
            throw new NotFoundHttpException('Product not found.');
        }

        $unitPrice = $product->promotion_price ?? $product->price;

        $lineTotal = $this->multiplyMoney(
            $unitPrice,
            $quantity,
        );

        $result = $this->carts->createItem(
            $cart,
            $product,
            $quantity,
            $unitPrice,
            $lineTotal,
        );

        if (! $result['created']) {
            throw new ConflictHttpException(
                'Product already exists in cart.',
            );
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
