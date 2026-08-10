<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1'],

            'product_id' => ['missing'],
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
     * @return array<string, mixed>
     */
    public function internalSession(): array
    {
        return $this->attributes->get('internal_session');
    }

    public function quantity(): int
    {
        return (int) $this->validated('quantity');
    }
}
