<?php

namespace App\Services\Handlers\Cart;

use App\Models\Cart;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Support\FulfillmentSelection;
use App\Services\Support\SessionSelection;
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
        $restaurant = $this->restaurants->findActiveById(SessionSelection::restaurantId($session));

        if ($restaurant === null) {
            throw new NotFoundHttpException;
        }

        FulfillmentSelection::assertReady($session);

        return $this->carts->findOrCreateForSession(
            $restaurant,
            $session['id'],
            Carbon::parse($session['expires_at']),
        );
    }
}
