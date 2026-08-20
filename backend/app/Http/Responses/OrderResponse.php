<?php

namespace App\Http\Responses;

use App\Models\Order;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;

readonly class OrderResponse implements Responsable
{
    public function __construct(
        private Order $order,
        private bool $created = false,
    ) {
    }

    public function toResponse($request): Response
    {
        return response()->json([
            'data' => [
                'id' => $this->order->id,
                'external_order_id' => $this->order->external_order_id,
                'status' => $this->order->status->value,
                'failure_message' => $this->order->failure_message,
                'receiving_type' => $this->order->receiving_type->value,
                'payment_type' => $this->order->payment_type,
                'fulfillment' => $this->order->fulfillment_snapshot,
                'total' => $this->order->total,
                'currency' => $this->order->currency,
                'payment' => [
                    'status' => $this->order->payment_checkout_url === null ? 'pending' : 'ready',
                    'checkout_url' => $this->order->payment_checkout_url,
                    'payment_received_at' => $this->order->payment_received_at?->toIso8601String(),
                    'qr_ready' => $this->order->payment_qr_path !== null
                        && $this->order->payment_qr_fingerprint !== null,
                ],
                'items' => $this->order->items->map(static fn ($item): array => [
                    'product_id' => $item->product_id,
                    'external_product_id' => $item->external_product_id,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total' => $item->total,
                ])->values()->all(),
            ],
        ], $this->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
