<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Http\Responses\OrderResponse;
use App\Services\Handlers\Order\ShowCurrentOrderHandler;
use Illuminate\Http\Request;

class OrderCurrentController extends Controller
{
    public function __invoke(
        Request $request,
        ShowCurrentOrderHandler $showCurrentOrder,
    ): OrderResponse {
        /** @var array{id: string} $session */
        $session = $request->attributes->get('internal_session');

        return new OrderResponse(
            $showCurrentOrder->handle($session),
        );
    }
}
