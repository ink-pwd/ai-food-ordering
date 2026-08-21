<?php

namespace App\Telegram\Support;

use App\DTO\Ai\AiPromptContextData;

final class AiPromptStore
{
    /** @var array<string, AiPromptContextData> */
    private array $prompts = [];

    public function put(
        int $chatId,
        int $messageId,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $this->forgetChat($chatId);
        $this->prompts[$this->key($chatId, $messageId)] = new AiPromptContextData(
            restaurantId: $restaurantId,
            fingerprint: $fingerprint,
        );
    }

    public function get(
        int $chatId,
        int $messageId,
    ): ?AiPromptContextData {
        return $this->prompts[$this->key($chatId, $messageId)] ?? null;
    }

    public function forget(int $chatId, int $messageId): void
    {
        unset($this->prompts[$this->key($chatId, $messageId)]);
    }

    public function forgetChat(int $chatId): void
    {
        $prefix = "{$chatId}:";

        foreach (array_keys($this->prompts) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->prompts[$key]);
            }
        }
    }

    private function key(int $chatId, int $messageId): string
    {
        return "{$chatId}:{$messageId}";
    }
}
