<?php

namespace App\Integrations\OrderingBackend;

use App\DTO\OrderingBackend\CurrentPaymentData;
use App\DTO\OrderingBackend\OrderData;
use App\DTO\OrderingBackend\OrderItemData;
use App\DTO\OrderingBackend\OrderPaymentData;
use Illuminate\Http\Client\Response;

final readonly class OrderOrderingBackendClient
{
    public function __construct(
        private OrderingBackendTransport $transport,
    ) {
    }

    public function createOrder(
        string $sessionToken,
        string $idempotencyKey,
        int $deliveryTime = 0,
    ): OrderData {
        $response = $this->transport->sessionBoundPost(
            sessionToken: $sessionToken,
            path: 'api/orders',
            operation: 'create_order',
            message: 'Unable to create the current ordering backend order.',
            data: [
                'delivery_time' => $deliveryTime,
            ],
            headers: [
                'Idempotency-Key' => $idempotencyKey,
            ],
        );

        return $this->orderFromResponse(
            $response,
            'create_order',
        );
    }

    public function currentOrder(
        string $sessionToken,
    ): OrderData {
        $response = $this->transport->sessionBoundGet(
            sessionToken: $sessionToken,
            path: 'api/orders/current',
            operation: 'get_current_order',
            message: 'Unable to retrieve the current ordering backend order.',
        );

        return $this->orderFromResponse(
            $response,
            'get_current_order',
        );
    }

    public function currentPayment(
        string $sessionToken,
    ): CurrentPaymentData {
        $response = $this->transport->sessionBoundGet(
            sessionToken: $sessionToken,
            path: 'api/orders/current/payment',
            operation: 'get_current_payment',
            message: 'Unable to retrieve the current ordering backend payment.',
        );

        $payment = $this->paymentStateFromResponse(
            $response,
            'get_current_payment',
        );

        return new CurrentPaymentData(
            status: $payment['status'],
            checkoutUrl: $payment['checkout_url'],
            paymentReceivedAt: $payment['payment_received_at'],
            httpStatus: $response->status(),
        );
    }

    /**
     * @return array{status: 'ready', content_type: string, contents: string}|array{status: 'pending', payment: CurrentPaymentData}
     */
    public function currentPaymentQr(
        string $sessionToken,
    ): array {
        $response = $this->transport->sessionBoundGet(
            sessionToken: $sessionToken,
            path: 'api/orders/current/payment/qr',
            operation: 'get_current_payment_qr',
            message: 'Unable to retrieve the current ordering backend payment QR.',
        );

        if ($response->status() === 202) {
            $payment = $this->paymentStateFromResponse(
                $response,
                'get_current_payment_qr',
            );

            return [
                'status' => 'pending',
                'payment' => new CurrentPaymentData(
                    status: $payment['status'],
                    checkoutUrl: $payment['checkout_url'],
                    paymentReceivedAt: $payment['payment_received_at'],
                    httpStatus: $response->status(),
                ),
            ];
        }

        $contentType = (string) $response->header('Content-Type');

        if (
            $response->status() !== 200
            || ! str_starts_with(
                $contentType,
                'image/png',
            )
        ) {
            throw $this->transport->invalidResponse(
                $response,
                'get_current_payment_qr',
                'Ordering backend returned malformed payment QR data.',
            );
        }

        return [
            'status' => 'ready',
            'content_type' => 'image/png',
            'contents' => $response->body(),
        ];
    }

    private function isValidOrder(mixed $order): bool
    {
        return is_array($order)
            && $this->transport->isPositiveInteger(
                $order['id'] ?? null,
            )
            && array_key_exists(
                'external_order_id',
                $order,
            )
            && $this->transport->isOptionalNonEmptyString(
                $order['external_order_id'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $order['status'] ?? null,
            )
            && array_key_exists(
                'failure_message',
                $order,
            )
            && $this->transport->isOptionalString(
                $order['failure_message'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $order['receiving_type'] ?? null,
            )
            && $this->transport->isPositiveInteger(
                $order['payment_type'] ?? null,
            )
            && is_array(
                $order['fulfillment'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $order['total'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $order['currency'] ?? null,
            )
            && is_array(
                $order['payment'] ?? null,
            )
            && is_array(
                $order['items'] ?? null,
            )
            && array_is_list($order['items']);
    }

    private function isValidOrderItem(mixed $item): bool
    {
        return is_array($item)
            && $this->transport->isOptionalPositiveInteger(
                $item['product_id'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $item['external_product_id'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $item['name'] ?? null,
            )
            && $this->transport->isPositiveInteger(
                $item['quantity'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $item['unit_price'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $item['total'] ?? null,
            );
    }

    private function isValidPaymentState(
        mixed $payment,
    ): bool {
        return is_array($payment)
            && $this->transport->isNonEmptyString(
                $payment['status'] ?? null,
            )
            && array_key_exists(
                'checkout_url',
                $payment,
            )
            && $this->transport->isOptionalNonEmptyString(
                $payment['checkout_url'] ?? null,
            )
            && array_key_exists(
                'payment_received_at',
                $payment,
            )
            && $this->transport->isOptionalNonEmptyString(
                $payment['payment_received_at'] ?? null,
            );
    }

    private function orderFromResponse(
        Response $response,
        string $operation,
    ): OrderData {
        $invalidMessage =
            'Ordering backend returned malformed order data.';

        $order = $this->transport->responseData(
            $response,
            $operation,
            $invalidMessage,
        );

        if (! $this->isValidOrder($order)) {
            throw $this->transport->invalidResponse(
                $response,
                $operation,
                $invalidMessage,
            );
        }

        /** @var array{
         *     id: int,
         *     external_order_id?: string|null,
         *     status: string,
         *     failure_message?: string|null,
         *     receiving_type: string,
         *     payment_type: int,
         *     fulfillment: array<string, mixed>,
         *     total: string,
         *     currency: string,
         *     payment: array<string, mixed>,
         *     items: list<mixed>
         * } $order
         */
        $payment = $this->paymentStateFromValue(
            $order['payment'],
            $response,
            $operation,
        );

        if (
            ! is_bool(
                $order['payment']['qr_ready'] ?? null,
            )
        ) {
            throw $this->transport->invalidResponse(
                $response,
                $operation,
                $invalidMessage,
            );
        }

        /** @var array{qr_ready: bool} $orderPayment */
        $orderPayment = $order['payment'];

        /** @var list<OrderItemData> $items */
        $items = array_map(
            function (mixed $item) use (
                $response,
                $operation,
                $invalidMessage,
            ): OrderItemData {
                if (! $this->isValidOrderItem($item)) {
                    throw $this->transport->invalidResponse(
                        $response,
                        $operation,
                        $invalidMessage,
                    );
                }

                /** @var array{product_id?: int|null, external_product_id: string, name: string, quantity: int, unit_price: string, total: string} $item */
                return new OrderItemData(
                    productId: $item['product_id'] ?? null,
                    externalProductId: $item['external_product_id'],
                    name: $item['name'],
                    quantity: $item['quantity'],
                    unitPrice: $item['unit_price'],
                    total: $item['total'],
                );
            },
            $order['items'],
        );

        return new OrderData(
            id: $order['id'],
            externalOrderId: $order['external_order_id'] ?? null,
            status: $order['status'],
            failureMessage: $order['failure_message'] ?? null,
            receivingType: $order['receiving_type'],
            paymentType: $order['payment_type'],
            fulfillment: $order['fulfillment'],
            total: $order['total'],
            currency: $order['currency'],
            payment: new OrderPaymentData(
                status: $payment['status'],
                checkoutUrl: $payment['checkout_url'],
                paymentReceivedAt: $payment['payment_received_at'],
                qrReady: $orderPayment['qr_ready'],
            ),
            items: $items,
        );
    }

    /**
     * @return array{status: string, checkout_url: ?string, payment_received_at: ?string}
     */
    private function paymentStateFromResponse(
        Response $response,
        string $operation,
    ): array {
        $invalidMessage =
            'Ordering backend returned malformed payment data.';

        $payment = $this->transport->responseData(
            $response,
            $operation,
            $invalidMessage,
        );

        return $this->paymentStateFromValue(
            $payment,
            $response,
            $operation,
        );
    }

    /**
     * @return array{status: string, checkout_url: ?string, payment_received_at: ?string}
     */
    private function paymentStateFromValue(
        mixed $payment,
        Response $response,
        string $operation,
    ): array {
        $invalidMessage =
            'Ordering backend returned malformed payment data.';

        if (! $this->isValidPaymentState($payment)) {
            throw $this->transport->invalidResponse(
                $response,
                $operation,
                $invalidMessage,
            );
        }

        /** @var array{status: string, checkout_url: string|null, payment_received_at: string|null} $payment */

        return [
            'status' => $payment['status'],
            'checkout_url' => $payment['checkout_url'] ?? null,
            'payment_received_at' => $payment['payment_received_at'] ?? null,
        ];
    }
}
