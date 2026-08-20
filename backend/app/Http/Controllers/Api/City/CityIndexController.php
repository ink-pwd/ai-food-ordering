<?php

namespace App\Http\Controllers\Api\City;

use App\Http\Controllers\Controller;
use App\Http\Resources\CityResource;
use App\Services\Handlers\City\ListCitiesHandler;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CityIndexController extends Controller
{
    public function __invoke(
        ListCitiesHandler $listCitiesHandler,
    ): AnonymousResourceCollection {
        return CityResource::collection(
            $listCitiesHandler->handle(),
        );
    }
}
