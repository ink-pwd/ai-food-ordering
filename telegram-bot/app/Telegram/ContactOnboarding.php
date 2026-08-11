<?php

namespace App\Telegram;

use App\Telegram\Keyboards\ContactRequestKeyboard;
use SergiX44\Nutgram\Nutgram;

final class ContactOnboarding
{
    public function __construct(
        private readonly ContactRequestKeyboard $contactRequest,
    ) {}

    public function start(Nutgram $bot): void
    {
        $this->request(
            bot: $bot,
            message: 'Чтобы продолжить, поделитесь своим контактом.',
        );
    }

    public function requestAfterSessionRecovery(Nutgram $bot): void
    {
        $this->request(
            bot: $bot,
            message: 'Сессия истекла. Пожалуйста, снова поделитесь контактом.',
        );
    }

    public function reportTemporaryFailure(Nutgram $bot): void
    {
        $this->request(
            bot: $bot,
            message: 'Сервис временно недоступен. Попробуйте снова позже.',
        );
    }

    public function request(Nutgram $bot, string $message): void
    {
        $bot->sendMessage(
            text: $message,
            reply_markup: $this->contactRequest->make(),
        );
    }
}
