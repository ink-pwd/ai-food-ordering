<?php

namespace App\Services\Handlers\Cart;

use App\Models\Cart;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Support\SessionSelection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ShowCurrentCartHandler
{
    public function __construct(
        private readonly RestaurantRepository $restaurants,
        private readonly CartRepository $carts,
    ) {}

    /**
     * @param  array{id: string, restaurant_id: int}  $session
     */
    public function handle(array $session): Cart
    {
        $restaurant = $this->restaurants->findActiveById(SessionSelection::restaurantId($session));

        if ($restaurant === null) {
            throw new NotFoundHttpException;
        }

        $cart = $this->carts->findForSession($restaurant, $session['id']);

        if ($cart === null) {
            throw new NotFoundHttpException('Cart was not found.');
        }

        return $cart;
    }
}
