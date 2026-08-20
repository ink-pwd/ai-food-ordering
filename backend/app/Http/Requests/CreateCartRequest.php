<?php

namespace App\Http\Requests;

use App\DTO\SessionData;
use Illuminate\Foundation\Http\FormRequest;

class CreateCartRequest extends FormRequest
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
            'session_id' => ['missing'],
            'restaurant_id' => ['missing'],
            'restaurant_slug' => ['missing'],
            'account_id' => ['missing'],
            'status' => ['missing'],
            'currency' => ['missing'],
            'subtotal' => ['missing'],
            'total' => ['missing'],
            'expires_at' => ['missing'],
            'items' => ['missing'],
        ];
    }

    public function internalSession(): SessionData
    {
        /** @var SessionData $session */
        $session = $this->attributes->get('internal_session');

        return $session;
    }
}
