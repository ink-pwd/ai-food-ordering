<?php

namespace App\Services\Handlers\Order;

use App\DTO\SessionData;
use App\Enums\OrderStatus;
use App\Integrations\Dots\OrdersApi;
use App\Models\Order;
use App\Services\Repositories\OrderRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class ShowCurrentOrderHandler
{
    public function __construct(
        private OrderRepository $orders,
        private OrdersApi $dotsOrders,
    ) {
    }

    public function handle(SessionData $session): Order
    {
        $order = $this->orders->findCurrentForSession($session->id);

        if ($order === null) {
            throw new NotFoundHttpException('Order was not found.');
        }

        if (! $this->needsDotsRefresh($order)) {
            return $order;
        }

        return $this->refreshFromDots($order);
    }

    private function needsDotsRefresh(Order $order): bool
    {
        return $order->status === OrderStatus::Creating
            && $order->external_order_id !== null;
    }

    private function refreshFromDots(Order $order): Order
    {
        /** @var string $externalOrderId */
        $externalOrderId = $order->external_order_id;

        try {
            $response = $this->dotsOrders->get(
                $externalOrderId,
            );
        } catch (RequestException $exception) {
            if (! $exception->response->notFound()) {
                Log::warning('Dots order status check failed.', [
                    'order_id' => $order->id,
                    'external_order_id' => $order->external_order_id,
                    'status_code' => $exception->response->status(),
                ]);
            }

            return $order;
        } catch (ConnectionException $exception) {
            Log::warning('Dots order status check connection failure.', [
                'order_id' => $order->id,
                'external_order_id' => $order->external_order_id,
            ]);

            return $order;
        }

        $this->orders->markCreated(
            $order,
            $response,
        );

        Log::info('Dots order creation confirmed.', [
            'order_id' => $order->id,
            'external_order_id' => $order->external_order_id,
        ]);

        return $this->orders->refreshWithItems($order);
    }
}
