<?php

namespace App\Services\Handlers\Cart;

use App\DTO\SessionData;
use App\Models\Cart;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Support\SessionSelection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class ShowCurrentCartHandler
{
    public function __construct(
        private RestaurantRepository $restaurants,
        private CartRepository $carts,
    ) {
    }

    public function handle(SessionData $session): Cart
    {
        $restaurant = $this->restaurants->findActiveById(SessionSelection::restaurantId($session));

        if ($restaurant === null) {
            throw new NotFoundHttpException;
        }

        $cart = $this->carts->findForSession($restaurant, $session->id);

        if ($cart === null) {
            throw new NotFoundHttpException('Cart was not found.');
        }

        return $cart;
    }
}
