<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\CurrentSessionRequest;
use App\Http\Responses\OrderTrackingResponse;
use App\Services\Handlers\Order\ShowOrderTrackingHandler;

class OrderTrackingController extends Controller
{
    public function __invoke(
        CurrentSessionRequest $request,
        ShowOrderTrackingHandler $showOrderTracking,
        string $order,
    ): OrderTrackingResponse {
        return new OrderTrackingResponse(
            $showOrderTracking->handle(
                $request->internalSession(),
                (int) $order,
            ),
        );
    }
}
