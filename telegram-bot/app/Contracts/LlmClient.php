<?php

namespace App\Contracts;

use App\DTO\Llm\LlmCompletionData;
use App\DTO\Llm\LlmRequestData;

interface LlmClient
{
    public function complete(LlmRequestData $request): LlmCompletionData;
}
