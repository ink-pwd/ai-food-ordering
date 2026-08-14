<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Services\Handlers\Order\ResolveOrderPaymentQrHandler;
use App\Services\Repositories\OrderRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrderPaymentQrController extends Controller
{
    public function __invoke(Request $request, OrderRepository $orders, ResolveOrderPaymentQrHandler $paymentQr): Response
    {
        $session = $request->attributes->get('internal_session');
        $order = $orders->findCurrentForSession($session['id']);

        if ($order === null) {
            throw new NotFoundHttpException('Order was not found.');
        }

        if ($order->payment_checkout_url === null) {
            return response()->json([
                'data' => [
                    'status' => 'pending',
                    'checkout_url' => null,
                    'payment_received_at' => null,
                ],
            ], Response::HTTP_ACCEPTED);
        }

        $result = $paymentQr->handle($order);

        return response($result['contents'], Response::HTTP_OK, [
            'Content-Type' => 'image/png',
        ]);
    }
}
