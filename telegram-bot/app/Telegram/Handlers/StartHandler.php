<?php

namespace App\Telegram\Handlers;

use App\Telegram\ContactOnboarding;
use App\Telegram\Session\TelegramSessionManager;
use SergiX44\Nutgram\Nutgram;

final class StartHandler
{
    public function __construct(
        private readonly TelegramSessionManager $sessions,
        private readonly ContactOnboarding $onboarding,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $this->sessions->resolve($bot);
        $this->onboarding->start($bot);
    }
}
