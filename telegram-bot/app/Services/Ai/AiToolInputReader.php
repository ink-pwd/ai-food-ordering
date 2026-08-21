<?php

namespace App\Services\Ai;

use App\DTO\Llm\LlmToolCallData;
use App\Exceptions\LlmException;
use JsonException;

final class AiToolInputReader
{
    public function nonEmptyString(
        LlmToolCallData $toolCall,
        string $field,
    ): string {
        $value = $this->arguments($toolCall)[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new LlmException(
                "LLM tool argument {$field} must be a non-empty string.",
            );
        }

        return trim($value);
    }

    public function positiveInteger(
        LlmToolCallData $toolCall,
        string $field,
    ): int {
        $value = $this->arguments($toolCall)[$field] ?? null;

        if (! is_int($value) || $value < 1) {
            throw new LlmException(
                "LLM tool argument {$field} must be a positive integer.",
            );
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function arguments(LlmToolCallData $toolCall): array
    {
        try {
            $arguments = json_decode(
                $toolCall->arguments,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new LlmException(
                'LLM returned malformed tool arguments.',
                $exception,
            );
        }

        if (! is_array($arguments) || array_is_list($arguments)) {
            throw new LlmException('LLM returned invalid tool arguments.');
        }

        /** @var array<string, mixed> $arguments */
        return $arguments;
    }
}
