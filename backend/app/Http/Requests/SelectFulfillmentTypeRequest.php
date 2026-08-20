<?php

namespace App\Http\Requests;

use App\DTO\SessionData;
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
        /** @var string $type */
        $type = $this->validated('type');

        return FulfillmentType::from((string) $type);
    }

    public function internalSession(): SessionData
    {
        /** @var SessionData $session */
        $session = $this->attributes->get('internal_session');

        return $session;
    }

    public function sessionToken(): string
    {
        return (string) $this->headers->get('X-Session-Token');
    }
}
