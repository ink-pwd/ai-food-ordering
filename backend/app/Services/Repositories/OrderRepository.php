<?php

namespace App\Services\Repositories;

use App\Enums\OrderStatus;
use App\Enums\ReceivingType;
use App\Enums\SessionChannel;
use App\Models\Order;
use App\Models\OrderItem;

class OrderRepository
{
    /**
     * @param  array<string, mixed>|null  $fulfillmentSnapshot
     * @param  array<string, mixed>|null  $requestPayload
     */
    public function create(
        int $restaurantId,
        int $cartId,
        string $sessionId,
        string $idempotencyKey,
        SessionChannel $channel,
        OrderStatus $status,
        ReceivingType $receivingType,
        int $paymentType,
        string $customerName,
        string $customerPhone,
        string $total,
        string $currency,
        ?array $fulfillmentSnapshot = null,
        ?array $requestPayload = null,
    ): Order {
        return Order::query()->create([
            'restaurant_id' => $restaurantId,
            'cart_id' => $cartId,
            'session_id' => $sessionId,
            'idempotency_key' => $idempotencyKey,
            'external_order_id' => null,
            'channel' => $channel,
            'status' => $status,
            'receiving_type' => $receivingType,
            'payment_type' => $paymentType,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'total' => $total,
            'currency' => $currency,
            'fulfillment_snapshot' => $fulfillmentSnapshot,
            'request_payload' => $requestPayload,
            'response_payload' => null,
            'failure_message' => null,
        ]);
    }

    public function createItem(
        Order $order,
        ?int $productId,
        string $externalProductId,
        string $name,
        int $quantity,
        string $unitPrice,
        string $total,
    ): OrderItem {
        return OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $productId,
            'external_product_id' => $externalProductId,
            'name' => $name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $total,
        ]);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?Order
    {
        return Order::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function findByIdempotencyKeyForUpdate(string $idempotencyKey): ?Order
    {
        return Order::query()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();
    }

    public function findForCart(int $cartId): ?Order
    {
        return Order::query()
            ->where('cart_id', $cartId)
            ->first();
    }

    public function findForCartForUpdate(int $cartId): ?Order
    {
        return Order::query()
            ->where('cart_id', $cartId)
            ->lockForUpdate()
            ->first();
    }

    public function findCurrentForSession(string $sessionId): ?Order
    {
        return Order::query()
            ->where('session_id', $sessionId)
            ->latest('id')
            ->with('items.product')
            ->first();
    }

    public function findByIdForCustomerPhone(int $orderId, string $customerPhone): ?Order
    {
        return Order::query()
            ->whereKey($orderId)
            ->where('customer_phone', $customerPhone)
            ->first();
    }

    /**
     * Dots accepted the create request and returned its asynchronous order id.
     * The order stays in Creating until a later status check confirms creation.
     *
     * @param  array<string, mixed>  $responsePayload
     */
    public function markAcceptedByDots(
        Order $order,
        string $externalOrderId,
        array $responsePayload,
    ): Order {
        $order->fill([
            'external_order_id' => $externalOrderId,
            'status' => OrderStatus::Creating,
            'response_payload' => $responsePayload,
            'failure_message' => null,
        ]);
        $order->save();

        return $order;
    }

    /** @param array<string, mixed> $responsePayload */
    public function markCreated(Order $order, array $responsePayload): Order
    {
        $order->fill([
            'status' => OrderStatus::Created,
            'response_payload' => $responsePayload,
            'failure_message' => null,
        ]);
        $order->save();

        return $order;
    }

    /** @param array<string, mixed>|null $responsePayload */
    public function markFailed(
        Order $order,
        string $message,
        ?array $responsePayload = null,
    ): Order {
        $order->fill([
            'status' => OrderStatus::Failed,
            'response_payload' => $responsePayload,
            'failure_message' => $message,
        ]);
        $order->save();

        return $order;
    }

    /** @param array<string, mixed>|null $responsePayload */
    public function markSubmissionUnknown(
        Order $order,
        string $message,
        ?array $responsePayload = null,
    ): Order {
        $order->fill([
            'status' => OrderStatus::Creating,
            'response_payload' => $responsePayload,
            'failure_message' => $message,
        ]);

        $order->save();

        return $order;
    }

    /** @param array<string, mixed> $paymentSnapshot */
    public function markPaymentReady(
        Order $order,
        string $checkoutUrl,
        array $paymentSnapshot,
    ): Order {
        $order->fill([
            'payment_checkout_url' => $checkoutUrl,
            'payment_snapshot' => $paymentSnapshot,
        ]);

        $order->save();

        return $order;
    }

    public function markPaymentQrReady(Order $order, string $path, string $fingerprint): Order
    {
        $order->fill([
            'payment_qr_path' => $path,
            'payment_qr_fingerprint' => $fingerprint,
        ]);
        $order->save();

        return $order;
    }

    public function refreshWithItems(Order $order): Order
    {
        return $order->refresh()->load('items.product');
    }
}
