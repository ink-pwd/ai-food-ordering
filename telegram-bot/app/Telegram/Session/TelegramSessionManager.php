<?php

namespace App\Telegram\Session;

use App\Integrations\OrderingBackend\OrderingBackendClient;
use RuntimeException;
use SergiX44\Nutgram\Nutgram;

final class TelegramSessionManager
{
    public function __construct(
        private readonly TelegramSessionStore $sessions,
        private readonly OrderingBackendClient $backend,
    ) {
    }

    public function resolve(Nutgram $bot): string
    {
        return $this->resolveForChatId($this->chatId($bot));
    }

    public function resolveForChatId(int $chatId): string
    {
        $telegramIdentifier = $this->telegramIdentifier($chatId);
        $sessionToken = $this->sessions->get($telegramIdentifier);

        if ($sessionToken !== null) {
            return $sessionToken;
        }

        $sessionToken = $this->backend->createTelegramSession($telegramIdentifier);
        $this->sessions->put($telegramIdentifier, $sessionToken);

        return $sessionToken;
    }

    public function storedToken(Nutgram $bot): ?string
    {
        return $this->sessions->get($this->telegramIdentifier($this->chatId($bot)));
    }

    public function forget(Nutgram $bot): void
    {
        $this->sessions->forget($this->telegramIdentifier($this->chatId($bot)));
    }

    private function chatId(Nutgram $bot): int
    {
        $chatId = $bot->chatId();

        if ($chatId === null) {
            throw new RuntimeException('Telegram update does not contain a chat identifier.');
        }

        return $chatId;
    }

    private function telegramIdentifier(int $chatId): string
    {
        return "telegram-chat-{$chatId}";
    }
}
