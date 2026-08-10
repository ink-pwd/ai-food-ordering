<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RestaurantProductSearchRequest extends FormRequest
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
            'q' => ['required', 'string', 'min:1', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function searchQuery(): string
    {
        return (string) $this->validated('q');
    }

    public function resultLimit(): int
    {
        return (int) ($this->validated('limit') ?? 10);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('q'))) {
            $this->merge([
                'q' => trim((string) $this->query('q')),
            ]);
        }
    }
}
