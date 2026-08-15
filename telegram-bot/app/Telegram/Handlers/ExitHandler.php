<?php

namespace App\Telegram\Handlers;

use App\Exceptions\OrderingBackendException;
use App\Telegram\CallbackAcknowledger;
use App\Telegram\ContactOnboarding;
use App\Telegram\Session\TelegramSessionManager;
use App\Telegram\Session\TelegramSessionRecovery;
use SergiX44\Nutgram\Nutgram;

final class ExitHandler
{
    public function __construct(
        private readonly CallbackAcknowledger $callbackAcknowledger,
        private readonly TelegramSessionManager $sessions,
        private readonly TelegramSessionRecovery $sessionRecovery,
        private readonly ContactOnboarding $onboarding,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $this->exit($bot);
    }

    public function command(Nutgram $bot): void
    {
        $this->exit($bot);
    }

    private function exit(Nutgram $bot): void
    {
        try {
            $this->sessionRecovery->deleteCurrentSession($bot);
        } catch (OrderingBackendException) {
            $this->sessions->forget($bot);
        }

        try {
            $this->sessions->resolve($bot);
        } catch (OrderingBackendException) {
            $this->onboarding->reportTemporaryFailure($bot);

            return;
        }

        $this->onboarding->request(
            bot: $bot,
            message: '🚪 Ви вийшли. Почнімо спочатку: надішліть свій номер телефону.',
        );
    }
}
