<?php

namespace App\Contracts;

use App\DTO\Ai\AiContextData;
use App\DTO\Ai\AiToolResultData;
use App\DTO\Llm\LlmToolCallData;

interface AiToolExecutor
{
    public function execute(
        LlmToolCallData $toolCall,
        AiContextData $context,
    ): AiToolResultData;
}
