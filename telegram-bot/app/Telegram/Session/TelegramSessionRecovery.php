<?php

namespace App\Telegram\Session;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\ContactOnboarding;
use SergiX44\Nutgram\Nutgram;

final class TelegramSessionRecovery
{
    public function __construct(
        private readonly TelegramSessionManager $sessions,
        private readonly OrderingBackendClient $backend,
        private readonly ContactOnboarding $onboarding,
    ) {
    }

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

    public function deleteCurrentSession(Nutgram $bot): void
    {
        $sessionToken = $this->sessions->storedToken($bot);
        $this->sessions->forget($bot);

        if ($sessionToken === null) {
            return;
        }

        try {
            $this->backend->deleteCurrentSession($sessionToken);
        } catch (OrderingBackendException $exception) {
            if ($exception->statusCode() !== 401) {
                throw $exception;
            }
        }
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
