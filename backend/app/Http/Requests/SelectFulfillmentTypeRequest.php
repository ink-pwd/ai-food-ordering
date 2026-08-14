<?php

namespace App\Http\Requests;

use App\Enums\FulfillmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SelectFulfillmentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', new Enum(FulfillmentType::class)],
            'city_id' => ['prohibited'],
            'restaurant_id' => ['prohibited'],
        ];
    }

    public function fulfillmentType(): FulfillmentType
    {
        return FulfillmentType::from((string) $this->validated('type'));
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
