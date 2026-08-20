<?php

namespace App\Http\Controllers\Api\Order;

use App\DTO\SessionData;
use App\Http\Controllers\Controller;
use App\Services\Handlers\Order\FindCurrentOrderHandler;
use App\Services\Handlers\Order\ResolveOrderPaymentHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderPaymentController extends Controller
{
    public function __invoke(
        Request $request,
        FindCurrentOrderHandler $findCurrentOrderHandler,
        ResolveOrderPaymentHandler $payments,
    ): JsonResponse {
        /** @var SessionData $session */
        $session = $request->attributes->get('internal_session');

        $order = $findCurrentOrderHandler->handle(
            $session->id,
        );

        $result = $payments->handle($order);

        return response()->json([
            'data' => [
                'status' => $result['status'],
                'checkout_url' => $result['checkout_url'],
                'payment_received_at' => $result['order']->payment_received_at?->toIso8601String(),
            ],
        ], $result['status'] === 'ready' ? Response::HTTP_OK : Response::HTTP_ACCEPTED);
    }
}
