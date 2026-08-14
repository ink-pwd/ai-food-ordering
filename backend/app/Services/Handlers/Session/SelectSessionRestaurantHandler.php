<?php

namespace App\Services\Handlers\Session;

use App\Models\Restaurant;
use App\Services\Repositories\CityRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Repositories\SessionRepository;
use App\Services\Support\SessionSelection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SelectSessionRestaurantHandler
{
    public function __construct(
        private readonly CityRepository $cities,
        private readonly RestaurantRepository $restaurants,
        private readonly SessionRepository $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     * @return array{session: array<string, mixed>, restaurant: Restaurant}
     */
    public function handle(string $plainToken, array $session, int $restaurantId): array
    {
        SessionSelection::assertPhoneVerified($session);

        $cityId = SessionSelection::cityId($session);

        if (($session['restaurant_id'] ?? null) !== null) {
            throw new ConflictHttpException('Restaurant has already been selected.');
        }

        $city = $this->cities->findActiveById($cityId);

        if ($city === null) {
            throw new NotFoundHttpException('City not found.');
        }

        $restaurant = $this->restaurants->findActiveForCityById($city, $restaurantId);

        if ($restaurant === null) {
            throw new NotFoundHttpException('Restaurant not found.');
        }

        $updatedSession = $this->sessions->selectRestaurant($plainToken, $restaurant->id);

        if ($updatedSession === null) {
            throw new NotFoundHttpException;
        }

        return [
            'session' => $updatedSession,
            'restaurant' => $restaurant,
        ];
    }
}
