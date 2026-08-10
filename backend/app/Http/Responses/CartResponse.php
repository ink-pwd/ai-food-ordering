<?php

namespace App\Http\Responses;

use App\Models\Cart;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CartResponse implements Responsable
{
    public function __construct(
        private readonly Cart $cart,
        private readonly bool $created = false,
        private readonly ?int $status = null,
    ) {}

    public function toResponse($request): Response
    {
        /** @var Request $request */

        return response()->json([
            'data' => [
                'id' => $this->cart->id,
                'status' => $this->cart->status->value,
                'currency' => $this->cart->currency,
                'subtotal' => $this->cart->subtotal,
                'total' => $this->cart->total,
                'expires_at' => $this->cart->expires_at->toIso8601String(),
                'items' => $this->cart->relationLoaded('items')
                    ? $this->cart->items->map(static fn ($item): array => [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'external_product_id' => $item->external_product_id,
                        'name' => $item->product->name,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'total' => $item->total,
                    ])->values()->all()
                    : [],
            ],
        ], $this->status ?? (
            $this->created
                ? Response::HTTP_CREATED
                : Response::HTTP_OK
        ));
    }
}
