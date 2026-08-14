<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSessionContactRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:32'],
            'phone_verified' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'restaurant_id' => ['prohibited'],
            'restaurant_slug' => ['prohibited'],
            'account_id' => ['prohibited'],
        ];
    }

    public function contactName(): string
    {
        return (string) $this->validated('name');
    }

    public function normalizedPhone(): string
    {
        return $this->normalizePhone((string) $this->validated('phone'));
    }

    public function sessionToken(): string
    {
        return (string) $this->headers->get('X-Session-Token');
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('phone')) {
                    return;
                }

                $phone = $this->input('phone');

                if (! is_string($phone)
                    || preg_match('/[^+\d\s\-()]/', $phone) === 1
                    || ! $this->isValidNormalizedPhone($this->normalizePhone($phone))
                ) {
                    $validator->errors()->add('phone', 'The phone field must be a valid phone number.');
                }
            },
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $normalizedPhone = str_replace([' ', '-', '(', ')'], '', $phone);

        if (preg_match('/[^+\d]/', $normalizedPhone) === 1) {
            return $normalizedPhone;
        }

        if (substr_count($normalizedPhone, '+') > 1 || (str_contains($normalizedPhone, '+') && ! str_starts_with($normalizedPhone, '+'))) {
            return $normalizedPhone;
        }

        if (str_starts_with($normalizedPhone, '00')) {
            $normalizedPhone = '+'.substr($normalizedPhone, 2);
        } elseif (preg_match('/\A0\d{9}\z/', $normalizedPhone) === 1) {
            $normalizedPhone = '+38'.$normalizedPhone;
        } elseif (preg_match('/\A380\d{9}\z/', $normalizedPhone) === 1) {
            $normalizedPhone = '+'.$normalizedPhone;
        }

        return $normalizedPhone;
    }

    private function isValidNormalizedPhone(string $phone): bool
    {
        return preg_match('/\A\+[1-9]\d{7,14}\z/', $phone) === 1;
    }
}
