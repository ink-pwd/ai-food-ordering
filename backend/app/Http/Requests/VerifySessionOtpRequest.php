<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifySessionOtpRequest extends FormRequest
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
            'code' => ['required', 'string', 'regex:/\A\d+\z/', 'max:10'],
            'phone' => ['prohibited'],
            'phone_verified' => ['prohibited'],
            'metadata' => ['prohibited'],
        ];
    }

    public function code(): string
    {
        return (string) $this->validated('code');
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
