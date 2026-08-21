<?php

namespace App\Telegram\Support;

final class OrderTrackingPromptStore
{
    /**
     * @var array<string, array{
     *     restaurant_id: int,
     *     fingerprint: string
     * }>
     */
    private array $prompts = [];

    public function put(
        int $chatId,
        int $messageId,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $this->prompts[$this->key($chatId, $messageId)] = [
            'restaurant_id' => $restaurantId,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * @return array{
     *     restaurant_id: int,
     *     fingerprint: string
     * }|null
     */
    public function get(int $chatId, int $messageId): ?array
    {
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
