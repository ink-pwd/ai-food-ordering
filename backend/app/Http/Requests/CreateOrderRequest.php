<?php

namespace App\Http\Requests;

use App\DTO\SessionData;
use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            '_idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * delivery_time = 0 means ASAP in Dots; a future Unix timestamp schedules fulfillment.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            '_idempotency_key' => ['required', 'string', 'max:128'],
            'delivery_time' => ['required', 'integer', 'min:0'],

            'idempotency_key' => ['missing'],
            'restaurant_id' => ['missing'],
            'restaurant_slug' => ['missing'],
            'company_id' => ['missing'],
            'company_address_id' => ['missing'],
            'city_id' => ['missing'],
            'receiving_type' => ['missing'],
            'delivery_type' => ['missing'],
            'payment_type' => ['missing'],
            'customer' => ['missing'],
            'items' => ['missing'],
            'total' => ['missing'],
            'currency' => ['missing'],
        ];
    }

    public function idempotencyKey(): string
    {
        /** @var string $idempotencyKey */
        $idempotencyKey = $this->validated('_idempotency_key');

        return (string) $idempotencyKey;
    }

    public function deliveryTime(): int
    {
        /** @var int|string|null $deliveryTime */
        $deliveryTime = $this->validated('delivery_time');

        return (int) ($deliveryTime ?? 0);
    }

    public function sessionToken(): string
    {
        return (string) $this->headers->get('X-Session-Token');
    }

    public function internalSession(): SessionData
    {
        /** @var SessionData $session */
        $session = $this->attributes->get('internal_session');

        return $session;
    }
}
