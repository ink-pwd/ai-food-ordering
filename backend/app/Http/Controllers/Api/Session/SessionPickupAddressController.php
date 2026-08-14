<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\SelectPickupAddressRequest;
use App\Services\Handlers\Fulfillment\SelectPickupAddressHandler;
use Illuminate\Http\JsonResponse;

class SessionPickupAddressController extends Controller
{
    public function __invoke(SelectPickupAddressRequest $request, SelectPickupAddressHandler $selectAddress): JsonResponse
    {
        $session = $selectAddress->handle(
            $request->sessionToken(),
            $request->internalSession(),
            $request->restaurantAddressId(),
        );

        return response()->json([
            'data' => [
                'session_id' => $session['id'],
                'fulfillment' => $session['fulfillment'],
            ],
        ]);
    }
}
