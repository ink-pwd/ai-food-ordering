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
        /** @var string $query */
        $query = $this->validated('q');

        return (string) $query;
    }

    public function resultLimit(): int
    {
        /** @var int|string|null $limit */
        $limit = $this->validated('limit');

        return (int) ($limit ?? 10);
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
