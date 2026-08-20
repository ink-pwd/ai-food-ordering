<?php

namespace App\Services\Handlers\Restaurant;

use App\DTO\SessionData;
use App\Models\City;
use App\Services\Repositories\CityRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Support\SessionSelection;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class ListSessionRestaurantsHandler
{
    public function __construct(
        private CityRepository $cities,
        private RestaurantRepository $restaurants,
    ) {
    }

    /**
     * @return Collection<int, \App\Models\Restaurant>
     */
    public function handle(SessionData $session): Collection
    {
        $city = $this->city($session);

        return $this->restaurants->activeForCity($city);
    }

    private function city(SessionData $session): City
    {
        $city = $this->cities->findActiveById(SessionSelection::cityId($session));

        if ($city === null) {
            throw new NotFoundHttpException('City not found.');
        }

        return $city;
    }
}
