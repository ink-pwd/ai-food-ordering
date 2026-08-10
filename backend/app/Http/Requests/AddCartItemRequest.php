<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1'],

            'cart_id' => ['missing'],
            'restaurant_id' => ['missing'],
            'session_id' => ['missing'],
            'external_product_id' => ['missing'],
            'unit_price' => ['missing'],
            'price' => ['missing'],
            'total' => ['missing'],
            'subtotal' => ['missing'],
            'currency' => ['missing'],
            'status' => ['missing'],
        ];
    }

    /**
     * @return array{id: string, restaurant_id: int, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}
     */
    public function internalSession(): array
    {
        return $this->attributes->get('internal_session');
    }

    public function productId(): int
    {
        return (int) $this->validated('product_id');
    }

    public function quantity(): int
    {
        return (int) $this->validated('quantity');
    }
}
