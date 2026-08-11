<?php

namespace App\Telegram\Session;

final class TelegramSessionStore
{
    /** @var array<string, string> */
    private array $sessionTokens = [];

    public function get(string $telegramIdentifier): ?string
    {
        return $this->sessionTokens[$telegramIdentifier] ?? null;
    }

    public function put(string $telegramIdentifier, string $sessionToken): void
    {
        $this->sessionTokens[$telegramIdentifier] = $sessionToken;
    }

    public function forget(string $telegramIdentifier): void
    {
        unset($this->sessionTokens[$telegramIdentifier]);
    }
}
