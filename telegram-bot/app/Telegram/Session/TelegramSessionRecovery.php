<?php

namespace App\Telegram\Session;

use App\Exceptions\OrderingBackendException;
use App\Telegram\ContactOnboarding;
use SergiX44\Nutgram\Nutgram;

final class TelegramSessionRecovery
{
    public function __construct(
        private readonly TelegramSessionManager $sessions,
        private readonly ContactOnboarding $onboarding,
    ) {}

    public function tokenOrRecover(Nutgram $bot): ?string
    {
        $sessionToken = $this->sessions->storedToken($bot);

        if ($sessionToken !== null) {
            return $sessionToken;
        }

        $this->createSessionAndRequestContact($bot);

        return null;
    }

    public function recoverIfUnauthorized(
        Nutgram $bot,
        OrderingBackendException $exception,
    ): bool {
        if ($exception->statusCode() !== 401) {
            return false;
        }

        $this->sessions->forget($bot);
        $this->createSessionAndRequestContact($bot);

        return true;
    }

    private function createSessionAndRequestContact(Nutgram $bot): void
    {
        try {
            $this->sessions->resolve($bot);
        } catch (OrderingBackendException) {
            $this->onboarding->reportTemporaryFailure($bot);

            return;
        }

        $this->onboarding->requestAfterSessionRecovery($bot);
    }
}
