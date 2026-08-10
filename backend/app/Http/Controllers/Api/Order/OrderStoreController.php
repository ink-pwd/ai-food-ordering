<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Responses\OrderResponse;
use App\Services\Handlers\Order\CreateOrderHandler;

class OrderStoreController extends Controller
{
    public function __invoke(
        CreateOrderRequest $request,
        CreateOrderHandler $createOrder,
    ): OrderResponse {
        $result = $createOrder->handle(
            $request->internalSession(),
            $request->idempotencyKey(),
            $request->deliveryTime(),
        );

        return new OrderResponse(
            $result['order'],
            $result['created'],
        );
    }
}
