<?php

namespace App\DTO\Llm;

final readonly class LlmCompletionData
{
    /** @param list<LlmToolCallData> $toolCalls */
    public function __construct(
        public ?string $content,
        public array $toolCalls = [],
    ) {
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
