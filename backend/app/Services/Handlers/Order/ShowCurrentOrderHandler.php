<?php

namespace App\Services\Handlers\Order;

use App\Enums\OrderStatus;
use App\Integrations\Dots\OrdersApi;
use App\Models\Order;
use App\Services\Repositories\OrderRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ShowCurrentOrderHandler
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrdersApi $dotsOrders,
    ) {}

    /** @param array{id: string} $session */
    public function handle(array $session): Order
    {
        $order = $this->orders->findCurrentForSession($session['id']);

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
        try {
            $response = $this->dotsOrders->get(
                $order->external_order_id,
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
