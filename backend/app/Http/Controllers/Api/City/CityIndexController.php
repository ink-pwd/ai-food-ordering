<?php

namespace App\Http\Controllers\Api\City;

use App\Http\Controllers\Controller;
use App\Http\Resources\CityResource;
use App\Services\Repositories\CityRepository;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CityIndexController extends Controller
{
    public function __invoke(CityRepository $cities): AnonymousResourceCollection
    {
        return CityResource::collection($cities->active());
    }
}
