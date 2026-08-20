<?php

namespace App\Http\Requests;

use App\DTO\SessionData;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
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

    public function internalSession(): SessionData
    {
        /** @var SessionData $session */
        $session = $this->attributes->get('internal_session');

        return $session;
    }

    public function quantity(): int
    {
        /** @var int|string $quantity */
        $quantity = $this->validated('quantity');

        return (int) $quantity;
    }
}
