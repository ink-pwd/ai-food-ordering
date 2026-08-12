<?php

namespace App\Mcp\Support;

use App\Integrations\OrderingBackend\OrderingBackendException;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;

final readonly class OrderResponseMapper
{
    public function __construct(private ValidationFactory $validator) {}

    /**
     * @param  array<array-key, mixed>  $response
     * @return array{id: int, external_order_id: string|null, status: string, receiving_type: string, total: string, currency: string, items: list<array{product_id: int|null, name: string, quantity: int, unit_price: string, total: string}>}
     */
    public function orderFromResponse(array $response): array
    {
        $validation = $this->validator->make($response, [
            'data' => ['required', 'array'],
            'data.id' => ['required', 'integer', 'min:1'],
            'data.external_order_id' => ['present', 'nullable', 'string'],
            'data.status' => ['required', 'string', 'in:draft,creating,created,awaiting_payment,paid,failed,cancelled'],
            'data.failure_message' => ['present', 'nullable', 'string'],
            'data.receiving_type' => ['required', 'string', 'in:pickup'],
            'data.total' => ['required', 'string', 'regex:/\A-?\d+\.\d{2}\z/'],
            'data.currency' => ['required', 'string', 'size:3'],
            'data.items' => ['present', 'array', 'list'],
            'data.items.*' => ['required', 'array'],
            'data.items.*.product_id' => ['present', 'nullable', 'integer', 'min:1'],
            'data.items.*.external_product_id' => ['required', 'string', 'uuid'],
            'data.items.*.name' => ['required', 'string'],
            'data.items.*.quantity' => ['required', 'integer', 'min:1'],
            'data.items.*.unit_price' => ['required', 'string', 'regex:/\A-?\d+\.\d{2}\z/'],
            'data.items.*.total' => ['required', 'string', 'regex:/\A-?\d+\.\d{2}\z/'],
        ]);

        if ($validation->fails()) {
            throw OrderingBackendException::invalidResponse();
        }

        $order = $response['data'];

        return [
            'id' => $order['id'],
            'external_order_id' => $order['external_order_id'],
            'status' => $order['status'],
            'receiving_type' => $order['receiving_type'],
            'total' => $order['total'],
            'currency' => $order['currency'],
            'items' => array_map(
                static fn (array $item): array => [
                    'product_id' => $item['product_id'],
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total' => $item['total'],
                ],
                $order['items'],
            ),
        ];
    }
}
