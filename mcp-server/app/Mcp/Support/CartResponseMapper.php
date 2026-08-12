<?php

namespace App\Mcp\Support;

use App\Integrations\OrderingBackend\OrderingBackendException;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;

final readonly class CartResponseMapper
{
    public function __construct(private ValidationFactory $validator) {}

    /**
     * @param  array<array-key, mixed>  $response
     * @return array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, name: string, quantity: int, unit_price: string, total: string}>}
     */
    public function cartFromResponse(array $response): array
    {
        $validation = $this->validator->make($response, [
            'data' => ['required', 'array'],
            'data.id' => ['required', 'integer', 'min:1'],
            'data.status' => ['required', 'string', 'in:active,checked_out,expired'],
            'data.currency' => ['required', 'string', 'size:3'],
            'data.subtotal' => ['required', 'string', 'regex:/\A-?\d+\.\d{2}\z/'],
            'data.total' => ['required', 'string', 'regex:/\A-?\d+\.\d{2}\z/'],
            'data.expires_at' => ['required', 'string', 'date_format:Y-m-d\TH:i:sP'],
            'data.items' => ['present', 'array', 'list'],
            'data.items.*' => ['required', 'array'],
            'data.items.*.id' => ['required', 'integer', 'min:1'],
            'data.items.*.product_id' => ['required', 'integer', 'min:1'],
            'data.items.*.external_product_id' => ['required', 'string'],
            'data.items.*.name' => ['required', 'string'],
            'data.items.*.quantity' => ['required', 'integer', 'min:1'],
            'data.items.*.unit_price' => ['required', 'string', 'regex:/\A-?\d+\.\d{2}\z/'],
            'data.items.*.total' => ['required', 'string', 'regex:/\A-?\d+\.\d{2}\z/'],
        ]);

        if ($validation->fails()) {
            throw OrderingBackendException::invalidResponse();
        }

        $cart = $response['data'];

        return [
            'id' => $cart['id'],
            'status' => $cart['status'],
            'currency' => $cart['currency'],
            'subtotal' => $cart['subtotal'],
            'total' => $cart['total'],
            'expires_at' => $cart['expires_at'],
            'items' => array_map(
                static fn (array $item): array => [
                    'id' => $item['id'],
                    'product_id' => $item['product_id'],
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total' => $item['total'],
                ],
                $cart['items'],
            ),
        ];
    }
}
