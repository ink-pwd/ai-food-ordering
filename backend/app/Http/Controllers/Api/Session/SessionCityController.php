<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\SelectSessionCityRequest;
use App\Http\Resources\CityResource;
use App\Services\Handlers\Session\SelectSessionCityHandler;
use Illuminate\Http\JsonResponse;

class SessionCityController extends Controller
{
    public function __invoke(SelectSessionCityRequest $request, SelectSessionCityHandler $selectCity): JsonResponse
    {
        $result = $selectCity->handle(
            $request->sessionToken(),
            $request->internalSession(),
            $request->cityId(),
        );

        return response()->json([
            'data' => [
                'session_id' => $result['session']['id'],
                'city' => (new CityResource($result['city']))->resolve($request),
            ],
        ]);
    }
}
