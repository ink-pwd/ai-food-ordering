<?php

namespace App\Services\Handlers\Restaurant;

use App\Models\City;
use App\Services\Repositories\CityRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Support\SessionSelection;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ListSessionRestaurantsHandler
{
    public function __construct(
        private readonly CityRepository $cities,
        private readonly RestaurantRepository $restaurants,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     * @return Collection<int, \App\Models\Restaurant>
     */
    public function handle(array $session): Collection
    {
        $city = $this->city($session);

        return $this->restaurants->activeForCity($city);
    }

    /** @param array<string, mixed> $session */
    private function city(array $session): City
    {
        $city = $this->cities->findActiveById(SessionSelection::cityId($session));

        if ($city === null) {
            throw new NotFoundHttpException('City not found.');
        }

        return $city;
    }
}
