<?php

namespace App\Telegram;

use App\Telegram\Keyboards\ContactRequestKeyboard;
use SergiX44\Nutgram\Nutgram;

final class ContactOnboarding
{
    public function __construct(
        private readonly ContactRequestKeyboard $contactRequest,
    ) {
    }

    public function start(Nutgram $bot): void
    {
        $this->request(
            bot: $bot,
            message: 'Щоб продовжити, надішліть свій номер телефону.',
        );
    }

    public function requestAfterSessionRecovery(Nutgram $bot): void
    {
        $this->request(
            bot: $bot,
            message: 'Сесію завершено. Почнімо спочатку: надішліть свій номер телефону.',
        );
    }

    public function reportTemporaryFailure(Nutgram $bot): void
    {
        $this->request(
            bot: $bot,
            message: 'Сервіс тимчасово недоступний. Спробуйте пізніше.',
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
