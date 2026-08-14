<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\CurrentSessionRequest;
use App\Http\Resources\RestaurantAddressResource;
use App\Services\Handlers\Fulfillment\PickupAddressIndexHandler;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SessionPickupAddressIndexController extends Controller
{
    public function __invoke(CurrentSessionRequest $request, PickupAddressIndexHandler $addresses): AnonymousResourceCollection
    {
        return RestaurantAddressResource::collection($addresses->handle($request->internalSession()));
    }
}
