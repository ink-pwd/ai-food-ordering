<?php

namespace App\Telegram\Handlers;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\CallbackAcknowledger;
use App\Telegram\Keyboards\CityKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final class CitySelectionHandler
{
    public function __construct(
        private readonly CallbackAcknowledger $callbackAcknowledger,
        private readonly TelegramSessionRecovery $sessionRecovery,
        private readonly OrderingBackendClient $backend,
        private readonly OnboardingFlow $onboarding,
        private readonly CityKeyboard $cityKeyboard,
        private readonly TelegramMessageEditor $messageEditor,
    ) {}

    public function select(Nutgram $bot, int $cityId): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        try {
            $this->backend->selectCurrentSessionCity($sessionToken, $cityId);
        } catch (OrderingBackendException $exception) {
            $this->handleSelectionFailure($bot, $exception, $sessionToken);

            return;
        }

        $this->onboarding->editRestaurants($bot, $sessionToken);
    }

    private function handleSelectionFailure(Nutgram $bot, OrderingBackendException $exception, string $sessionToken): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        if ($exception->statusCode() === 409) {
            $this->messageEditor->edit(
                bot: $bot,
                text: '🏙 Місто вже обрано для цієї сесії. Продовжимо з вибором ресторану.',
                keyboard: $this->cityKeyboard->make([]),
            );
            $this->onboarding->renderRestaurants($bot, $sessionToken);

            return;
        }

        $message = match ($exception->statusCode()) {
            404 => '🏙 Обране місто недоступне. Оберіть інше місто.',
            422 => '🏙 Не вдалося обрати місто. Спробуйте ще раз.',
            default => 'Не вдалося обрати місто. Спробуйте пізніше.',
        };

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->cityKeyboard->make([]),
        );
    }
}
