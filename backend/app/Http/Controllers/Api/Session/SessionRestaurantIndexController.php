<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\CurrentSessionRequest;
use App\Http\Resources\RestaurantResource;
use App\Services\Handlers\Restaurant\ListSessionRestaurantsHandler;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SessionRestaurantIndexController extends Controller
{
    public function __invoke(CurrentSessionRequest $request, ListSessionRestaurantsHandler $restaurants): AnonymousResourceCollection
    {
        return RestaurantResource::collection($restaurants->handle($request->internalSession()));
    }
}
