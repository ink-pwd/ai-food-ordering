<?php

namespace App\Telegram\Support;

final class DeliveryAddressPromptStore
{
    /**
     * @var array<string, array{
     *     type: int,
     *     restaurant_id: int,
     *     fingerprint: string
     * }>
     */
    private array $prompts = [];

    public function put(
        int $chatId,
        int $messageId,
        int $type,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $this->prompts[$this->key($chatId, $messageId)] = [
            'type' => $type,
            'restaurant_id' => $restaurantId,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * @return array{
     *     type: int,
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

    private function key(int $chatId, int $messageId): string
    {
        return "{$chatId}:{$messageId}";
    }
}
