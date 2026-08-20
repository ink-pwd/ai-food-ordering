<?php

namespace App\Telegram;

use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

final class CallbackAcknowledger
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function acknowledge(Nutgram $bot): bool
    {
        try {
            $bot->answerCallbackQuery();
        } catch (TelegramException $exception) {
            if (! $this->isStale($exception)) {
                throw $exception;
            }

            $this->logger->debug('Stale Telegram callback query ignored.', [
                'exception' => $exception::class,
                'code' => $exception->getCode(),
            ]);

            return false;
        }

        return true;
    }

    private function isStale(TelegramException $exception): bool
    {
        return Str::contains(Str::lower($exception->getMessage()), [
            'query is too old',
            'response timeout expired',
            'query id is invalid',
        ]);
    }
}
