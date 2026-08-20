<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\ValidateDeliveryAddressRequest;
use App\Services\Handlers\Fulfillment\ValidateDeliveryAddressHandler;
use Illuminate\Http\JsonResponse;

class SessionDeliveryAddressController extends Controller
{
    public function __invoke(
        ValidateDeliveryAddressRequest $request,
        ValidateDeliveryAddressHandler $validateAddress,
    ): JsonResponse {
        $result = $validateAddress->handle(
            $request->sessionToken(),
            $request->internalSession(),
            $request->addressPayload(),
        );

        return response()->json([
            'data' => [
                'session_id' => $result->session->id,
                'delivery_available' => $result->deliveryAvailable,
                'reason' => $result->reason,
                'delivery_price' => $result->deliveryPrice,
                'dots_delivery_type' => $result->dotsDeliveryType,
                'fulfillment' => $result->session->fulfillment,
            ],
        ]);
    }
}
