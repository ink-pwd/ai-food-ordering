<?php

namespace App\DTO\Ai;

final readonly class AiPromptContextData
{
    public function __construct(
        public int $restaurantId,
        public string $fingerprint,
    ) {
    }
}
