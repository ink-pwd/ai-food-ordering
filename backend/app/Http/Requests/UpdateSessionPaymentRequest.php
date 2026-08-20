<?php

namespace App\Http\Requests;

use App\DTO\SessionData;
use App\Enums\PaymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSessionPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'payment_type' => [
                'required',
                'integer',
                Rule::enum(PaymentType::class),
            ],
        ];
    }

    public function paymentType(): PaymentType
    {
        /** @var int|string $paymentType */
        $paymentType = $this->validated('payment_type');

        return PaymentType::from(
            (int) $paymentType,
        );
    }

    public function internalSession(): SessionData
    {
        /** @var SessionData $session */
        $session = $this->attributes->get('internal_session');

        return $session;
    }
}
