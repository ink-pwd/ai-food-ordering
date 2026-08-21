<?php

namespace App\Telegram\Support;

use App\DTO\Llm\LlmMessageData;
use App\Services\Ai\AiSettings;

final class AiConversationStore
{
    public function __construct(
        private readonly AiSettings $settings,
    ) {
    }

    /** @var array<int, list<LlmMessageData>> */
    private array $messages = [];

    /** @return list<LlmMessageData> */
    public function get(int $chatId): array
    {
        return $this->messages[$chatId] ?? [];
    }

    public function appendUserAndAssistant(
        int $chatId,
        string $userMessage,
        string $assistantMessage,
    ): void {
        $messages = $this->messages[$chatId] ?? [];
        $messages[] = LlmMessageData::user($userMessage);
        $messages[] = LlmMessageData::assistant($assistantMessage);

        $limit = max(2, $this->settings->historyMessages());
        $this->messages[$chatId] = array_slice(
            $messages,
            -$limit,
        );
    }

    public function forget(int $chatId): void
    {
        unset($this->messages[$chatId]);
    }
}
