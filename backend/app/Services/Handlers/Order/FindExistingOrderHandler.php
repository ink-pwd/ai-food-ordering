<?php

namespace App\Services\Handlers\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Repositories\OrderRepository;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

readonly class FindExistingOrderHandler
{
    public function __construct(
        private OrderRepository $orders,
    ) {
    }

    public function handle(
        string $idempotencyKey,
        string $sessionId,
    ): ?Order {
        $order = $this->orders->findByIdempotencyKey(
            $idempotencyKey,
        );

        if ($order === null) {
            return null;
        }

        if ($order->session_id !== $sessionId) {
            throw new ConflictHttpException(
                'Idempotency key is already in use.',
            );
        }

        /** @var OrderStatus $status */
        $status = $order->status;

        Log::info('Order idempotency hit.', [
            'order_id' => $order->id,
            'status' => $status->value,
        ]);

        return $order;
    }
}
