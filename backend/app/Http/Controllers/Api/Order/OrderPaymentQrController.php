<?php

namespace App\Http\Controllers\Api\Order;

use App\DTO\SessionData;
use App\Http\Controllers\Controller;
use App\Services\Handlers\Order\FindCurrentOrderHandler;
use App\Services\Handlers\Order\ResolveOrderPaymentQrHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderPaymentQrController extends Controller
{
    public function __invoke(
        Request $request,
        FindCurrentOrderHandler $findCurrentOrderHandler,
        ResolveOrderPaymentQrHandler $paymentQr,
    ): Response {
        /** @var SessionData $session */
        $session = $request->attributes->get('internal_session');

        $order = $findCurrentOrderHandler->handle(
            $session->id,
        );

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
