<?php

namespace App\DTO\Llm;

final readonly class LlmRequestData
{
    /**
     * @param  list<LlmMessageData>  $messages
     * @param  list<LlmToolDefinitionData>  $tools
     */
    public function __construct(
        public array $messages,
        public array $tools = [],
    ) {
    }
}
