<?php

namespace App\Services\Handlers\Order;

use App\DTO\SessionData;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Services\Repositories\OrderRepository;

readonly class CreateOrderHandler
{
    public function __construct(
        private OrderRepository $orders,
        private FindExistingOrderHandler $findExistingOrder,
        private PrepareOrderCheckoutHandler $prepareCheckout,
        private BuildOrderPayloadHandler $buildPayload,
        private ValidateOrderPricesHandler $validatePrices,
        private CreateLocalOrderHandler $createLocalOrder,
        private SubmitOrderToDotsHandler $submitToDots,
        private ResolveOrderPaymentHandler $payments,
    ) {
    }

    /**
     * @return array{order: Order, created: bool}
     */
    public function handle(
        SessionData $session,
        string $plainToken,
        string $idempotencyKey,
        int $deliveryTime,
    ): array {
        $existing = $this->findExistingOrder->handle(
            $idempotencyKey,
            $session->id,
        );

        if ($existing !== null) {
            if ($this->needsPaymentResolution($existing)) {
                $existing = $this->payments->handle(
                    $existing,
                )['order'];
            }

            return $this->result(
                $existing,
                false,
            );
        }

        $checkout = $this->prepareCheckout->handle(
            $session,
            $plainToken,
        );

        $payload = $this->buildPayload->handle(
            $checkout,
            $deliveryTime,
        );

        $pricing = $this->validatePrices->handle(
            $payload,
            $checkout,
        );

        $localResult = $this->createLocalOrder->handle(
            checkout: $checkout,
            session: $session,
            idempotencyKey: $idempotencyKey,
            payload: $payload,
            pricing: $pricing,
        );

        if (! $localResult['created']) {
            return $this->result(
                $localResult['order'],
                false,
            );
        }

        $order = $localResult['order'];

        $this->submitToDots->handle(
            $order,
            $payload,
        );

        if (
            $checkout->paymentType
                ->requiresOnlinePayment()
        ) {
            $order = $this->payments->handle(
                $order,
            )['order'];
        }

        return $this->result(
            $order,
            true,
        );
    }

    private function needsPaymentResolution(
        Order $order,
    ): bool {
        return PaymentType::tryFrom(
            (int) $order->payment_type,
        )?->requiresOnlinePayment() === true
            && $order->payment_checkout_url === null
            && $order->external_order_id !== null;
    }

    /**
     * @return array{order: Order, created: bool}
     */
    private function result(
        Order $order,
        bool $created,
    ): array {
        return [
            'order' => $this->orders
                ->refreshWithItems($order),
            'created' => $created,
        ];
    }
}
