<?php

namespace App\Services\Handlers\Cart;

use App\Models\Cart;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\RestaurantRepository;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CreateCartHandler
{
    public function __construct(
        private readonly RestaurantRepository $restaurants,
        private readonly CartRepository $carts,
    ) {}

    /**
     * @param  array{id: string, restaurant_id: int, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}  $session
     * @return array{cart: Cart, created: bool}
     */
    public function handle(array $session): array
    {
        $restaurant = $this->restaurants->findActiveById($session['restaurant_id']);

        if ($restaurant === null) {
            throw new NotFoundHttpException;
        }

        return $this->carts->findOrCreateForSession(
            $restaurant,
            $session['id'],
            Carbon::parse($session['expires_at']),
        );
    }
}
