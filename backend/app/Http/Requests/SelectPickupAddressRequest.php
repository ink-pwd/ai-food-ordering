<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SelectPickupAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'restaurant_address_id' => ['required', 'integer', 'min:1'],
            'external_address_id' => ['prohibited'],
            'restaurant_id' => ['prohibited'],
        ];
    }

    public function restaurantAddressId(): int
    {
        return (int) $this->validated('restaurant_address_id');
    }

    /** @return array<string, mixed> */
    public function internalSession(): array
    {
        return $this->attributes->get('internal_session');
    }

    public function sessionToken(): string
    {
        return (string) $this->headers->get('X-Session-Token');
    }
}
