<?php

namespace App\Services\Handlers\Cart;

use App\DTO\SessionData;
use App\Models\Cart;
use App\Models\Restaurant;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Resolvers\Cart\ActiveCartForUpdateResolver;
use App\Services\Resolvers\Cart\CartItemForUpdateResolver;
use App\Services\Support\SessionSelection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class RemoveCartItemHandler
{
    public function __construct(
        private readonly RestaurantRepository $restaurants,
        private readonly CartRepository $carts,
        private readonly ActiveCartForUpdateResolver $activeCartForUpdateResolver,
        private readonly CartItemForUpdateResolver $cartItemForUpdateResolver,
    ) {
    }

    public function handle(
        SessionData $session,
        int $itemId,
    ): Cart {
        $restaurant = $this->restaurants->findActiveById(
            SessionSelection::restaurantId($session),
        );

        if ($restaurant === null) {
            throw new NotFoundHttpException;
        }

        // The cart/item rows are locked below; item deletion and total recalculation must commit atomically.
        return DB::transaction(
            fn (): Cart => $this->removeItemAndRecalculateTotals(
                $restaurant,
                $session,
                $itemId,
            ),
        );
    }

    private function removeItemAndRecalculateTotals(
        Restaurant $restaurant,
        SessionData $session,
        int $itemId,
    ): Cart {
        $cart = $this->activeCartForUpdateResolver->resolve(
            $restaurant,
            $session->id,
        );

        $item = $this->cartItemForUpdateResolver->resolve(
            $cart,
            $itemId,
        );

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

            $cents += ((int) $whole * 100)
                + (int) $fraction;
        }

        return sprintf(
            '%d.%02d',
            intdiv($cents, 100),
            $cents % 100,
        );
    }
}
