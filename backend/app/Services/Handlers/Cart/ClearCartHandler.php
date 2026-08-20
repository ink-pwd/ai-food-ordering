<?php

namespace App\Services\Handlers\Cart;

use App\DTO\SessionData;
use App\Models\Cart;
use App\Models\Restaurant;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Resolvers\Cart\ActiveCartForUpdateResolver;
use App\Services\Support\SessionSelection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class ClearCartHandler
{
    public function __construct(
        private readonly RestaurantRepository $restaurants,
        private readonly CartRepository $carts,
        private readonly ActiveCartForUpdateResolver $activeCartForUpdateResolver,
    ) {
    }

    /**
     * @throws \Throwable
     */
    public function handle(SessionData $session): Cart
    {
        $restaurant = $this->restaurants->findActiveById(
            SessionSelection::restaurantId($session),
        );

        if ($restaurant === null) {
            throw new NotFoundHttpException;
        }

        // The cart row is locked below; item deletion and total reset must commit atomically.
        return DB::transaction(
            fn (): Cart => $this->clearItemsAndResetTotals(
                $restaurant,
                $session,
            ),
        );
    }

    private function clearItemsAndResetTotals(
        Restaurant $restaurant,
        SessionData $session,
    ): Cart {
        $cart = $this->activeCartForUpdateResolver->resolve(
            $restaurant,
            $session->id,
        );

        $this->carts->deleteItems($cart);

        $this->carts->updateTotals(
            $cart,
            '0.00',
            '0.00',
        );

        return $this->carts->refreshWithItems($cart);
    }
}
