<?php

namespace App\Http\Requests;

use App\DTO\SessionData;
use Illuminate\Foundation\Http\FormRequest;

class ValidateDeliveryAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'integer'],
            'street' => ['required', 'string', 'max:255'],
            'house' => ['required', 'string', 'max:255'],
            'flat' => ['nullable', 'string', 'max:255'],
            'stage' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'title' => ['nullable', 'string', 'max:255'],
            'cityId' => ['prohibited'],
            'city_id' => ['prohibited'],
            'latitude' => ['prohibited'],
            'longitude' => ['prohibited'],
            'position' => ['prohibited'],
        ];
    }

    /** @return array<string, mixed> */
    public function addressPayload(): array
    {
        return $this->validated();
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
