<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SelectSessionRestaurantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'restaurant_id' => ['required', 'integer', 'min:1'],
            'city_id' => ['prohibited'],
            'restaurant_slug' => ['prohibited'],
            'metadata' => ['prohibited'],
        ];
    }

    public function restaurantId(): int
    {
        return (int) $this->validated('restaurant_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function internalSession(): array
    {
        return $this->attributes->get('internal_session');
    }

    public function sessionToken(): string
    {
        return (string) $this->headers->get('X-Session-Token');
    }
}
