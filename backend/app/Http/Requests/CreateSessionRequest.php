<?php

namespace App\Http\Requests;

use App\Enums\SessionChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class CreateSessionRequest extends FormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', new Enum(SessionChannel::class)],
            'external_session_id' => ['required', 'string', 'max:255'],
            'restaurant_id' => ['prohibited'],
            'restaurant_slug' => ['prohibited'],
            'account_id' => ['prohibited'],
        ];
    }

    public function channel(): SessionChannel
    {
        return SessionChannel::from((string) $this->validated('channel'));
    }

    public function externalSessionId(): string
    {
        return (string) $this->validated('external_session_id');
    }
}
