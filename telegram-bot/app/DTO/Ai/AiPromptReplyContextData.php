<?php

namespace App\DTO\Ai;

final readonly class AiPromptReplyContextData
{
    public function __construct(
        public int $chatId,
        public int $promptMessageId,
        public int $restaurantId,
        public string $fingerprint,
    ) {
    }
}
