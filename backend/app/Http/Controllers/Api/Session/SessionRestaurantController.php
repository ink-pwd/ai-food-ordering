<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\SelectSessionRestaurantRequest;
use App\Http\Resources\RestaurantResource;
use App\Services\Handlers\Session\SelectSessionRestaurantHandler;
use Illuminate\Http\JsonResponse;

class SessionRestaurantController extends Controller
{
    public function __invoke(SelectSessionRestaurantRequest $request, SelectSessionRestaurantHandler $selectRestaurant): JsonResponse
    {
        $result = $selectRestaurant->handle(
            $request->sessionToken(),
            $request->internalSession(),
            $request->restaurantId(),
        );

        return response()->json([
            'data' => [
                'session_id' => $result['session']['id'],
                'restaurant' => (new RestaurantResource($result['restaurant']))->resolve($request),
            ],
        ]);
    }
}
