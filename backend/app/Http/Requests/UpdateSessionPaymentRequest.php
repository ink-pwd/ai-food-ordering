<?php

namespace App\Http\Requests;

use App\Enums\PaymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSessionPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
        return PaymentType::from(
            (int) $this->validated('payment_type')
        );
    }
}
