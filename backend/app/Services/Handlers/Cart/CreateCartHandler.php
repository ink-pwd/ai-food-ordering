<?php

namespace App\Services\Handlers\Cart;

use App\DTO\SessionData;
use App\Models\Cart;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Support\FulfillmentSelection;
use App\Services\Support\SessionSelection;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class CreateCartHandler
{
    public function __construct(
        private RestaurantRepository $restaurants,
        private CartRepository $carts,
    ) {
    }

    /**
     * @return array{cart: Cart, created: bool}
     */
    public function handle(SessionData $session): array
    {
        $restaurant = $this->restaurants->findActiveById(SessionSelection::restaurantId($session));

        if ($restaurant === null) {
            throw new NotFoundHttpException;
        }

        FulfillmentSelection::assertReady($session);

        return $this->carts->findOrCreateForSession(
            $restaurant,
            $session->id,
            Carbon::parse($session->expiresAt),
        );
    }
}
