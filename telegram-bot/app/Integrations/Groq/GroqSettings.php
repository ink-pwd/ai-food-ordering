<?php

namespace App\Integrations\Groq;

use App\Exceptions\LlmException;

final class GroqSettings
{
    public function apiKey(): string
    {
        $value = config('llm.groq.api_key');

        if (! is_string($value) || trim($value) === '') {
            throw new LlmException('Groq API key is not configured.');
        }

        return trim($value);
    }

    public function baseUrl(): string
    {
        return $this->nonEmptyString(
            'llm.groq.base_url',
            'https://api.groq.com/openai/v1',
        );
    }

    public function model(): string
    {
        return $this->nonEmptyString(
            'llm.groq.model',
            'openai/gpt-oss-20b',
        );
    }

    public function timeout(): int
    {
        return $this->positiveInteger(
            'llm.groq.timeout',
            20,
        );
    }

    public function maxCompletionTokens(): int
    {
        return $this->positiveInteger(
            'llm.groq.max_completion_tokens',
            512,
        );
    }

    private function nonEmptyString(
        string $key,
        string $default,
    ): string {
        $value = config($key, $default);

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : $default;
    }

    private function positiveInteger(
        string $key,
        int $default,
    ): int {
        $value = config($key, $default);

        return is_int($value) && $value > 0
            ? $value
            : $default;
    }
}
