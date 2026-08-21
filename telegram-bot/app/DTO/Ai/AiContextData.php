<?php

namespace App\DTO\Ai;

final readonly class AiContextData
{
    public function __construct(
        public string $sessionToken,
        public string $callbackContext,
        public int $restaurantId,
        public string $restaurantSlug,
    ) {
    }
}
