<?php

namespace App\DTO\Ai;

final readonly class AiToolResultData
{
    public function __construct(
        public string $content,
    ) {
    }
}
