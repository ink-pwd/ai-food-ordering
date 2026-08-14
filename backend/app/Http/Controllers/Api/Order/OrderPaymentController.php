<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Services\Handlers\Order\ResolveOrderPaymentHandler;
use App\Services\Repositories\OrderRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrderPaymentController extends Controller
{
    public function __invoke(Request $request, OrderRepository $orders, ResolveOrderPaymentHandler $payments): JsonResponse
    {
        $session = $request->attributes->get('internal_session');
        $order = $orders->findCurrentForSession($session['id']);

        if ($order === null) {
            throw new NotFoundHttpException('Order was not found.');
        }

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
