<?php

namespace App\Services\Handlers\Order;

use App\Models\Order;
use App\Services\Repositories\OrderRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class FindCurrentOrderHandler
{
    public function __construct(
        private OrderRepository $orders,
    ) {
    }

    public function handle(string $sessionId): Order
    {
        $order = $this->orders->findCurrentForSession($sessionId);

        if ($order === null) {
            throw new NotFoundHttpException('Order was not found.');
        }

        return $order;
    }
}
