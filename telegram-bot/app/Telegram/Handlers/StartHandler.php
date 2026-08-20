<?php

namespace App\Telegram\Handlers;

use App\Exceptions\OrderingBackendException;
use App\Telegram\ContactOnboarding;
use App\Telegram\Session\TelegramSessionManager;
use SergiX44\Nutgram\Nutgram;

final readonly class StartHandler
{
    public function __construct(
        private TelegramSessionManager $sessions,
        private ContactOnboarding $onboarding,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        try {
            $this->sessions->resolve($bot);
        } catch (OrderingBackendException) {
            $this->onboarding->reportTemporaryFailure($bot);

            return;
        }

        $this->onboarding->start($bot);
    }
}
