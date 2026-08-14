<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SelectSessionCityRequest extends FormRequest
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
            'city_id' => ['required', 'integer', 'min:1'],
            'restaurant_id' => ['prohibited'],
            'restaurant_slug' => ['prohibited'],
            'metadata' => ['prohibited'],
        ];
    }

    public function cityId(): int
    {
        return (int) $this->validated('city_id');
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
