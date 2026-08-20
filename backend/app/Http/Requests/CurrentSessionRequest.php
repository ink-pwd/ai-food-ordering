<?php

namespace App\Http\Requests;

use App\DTO\SessionData;
use Illuminate\Foundation\Http\FormRequest;

class CurrentSessionRequest extends FormRequest
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
        return [];
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
