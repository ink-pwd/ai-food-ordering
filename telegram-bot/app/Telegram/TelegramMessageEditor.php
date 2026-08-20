<?php

namespace App\Telegram;

use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class TelegramMessageEditor
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function edit(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        try {
            $bot->editMessageText(
                text: $text,
                reply_markup: $keyboard,
            );
        } catch (TelegramException $exception) {
            if (! $this->isUnchangedMessage($exception)) {
                throw $exception;
            }

            $this->logger->debug('Unchanged Telegram message edit ignored.', [
                'exception' => $exception::class,
                'code' => $exception->getCode(),
            ]);
        }
    }

    private function isUnchangedMessage(TelegramException $exception): bool
    {
        return Str::contains(
            Str::lower($exception->getMessage()),
            'message is not modified',
        );
    }
}
