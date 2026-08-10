<?php

namespace App\Http\Responses;

use App\Models\Order;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;

class OrderResponse implements Responsable
{
    public function __construct(
        private readonly Order $order,
        private readonly bool $created = false,
    ) {}

    public function toResponse($request): Response
    {
        return response()->json([
            'data' => [
                'id' => $this->order->id,
                'external_order_id' => $this->order->external_order_id,
                'status' => $this->order->status->value,
                'failure_message' => $this->order->failure_message,
                'receiving_type' => $this->order->receiving_type->value,
                'total' => $this->order->total,
                'currency' => $this->order->currency,
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
