<?php

namespace App\Services\Handlers\Fulfillment;

use App\DTO\SessionData;
use App\Models\City;
use App\Models\Restaurant;
use App\Services\Repositories\CityRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Support\SessionSelection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait ResolvesSessionRestaurant
{
    private function resolveCity(SessionData $session, CityRepository $cities): City
    {
        $city = $cities->findActiveById(SessionSelection::cityId($session));

        if ($city === null) {
            throw new NotFoundHttpException('City not found.');
        }

        return $city;
    }

    private function resolveRestaurant(SessionData $session, City $city, RestaurantRepository $restaurants): Restaurant
    {
        $restaurant = $restaurants->findActiveForCityById(
            $city,
            SessionSelection::restaurantId($session),
        );

        if ($restaurant === null) {
            throw new NotFoundHttpException('Restaurant not found.');
        }

        return $restaurant;
    }
}
