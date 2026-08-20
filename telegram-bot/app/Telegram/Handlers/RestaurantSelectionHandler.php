<?php

namespace App\Telegram\Handlers;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\CallbackAcknowledger;
use App\Telegram\Keyboards\RestaurantKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\Support\RestaurantNavigationContext;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final readonly class RestaurantSelectionHandler
{
    public function __construct(
        private CallbackAcknowledger $callbackAcknowledger,
        private TelegramSessionRecovery $sessionRecovery,
        private OrderingBackendClient $backend,
        private OnboardingFlow $onboarding,
        private RestaurantKeyboard $restaurantKeyboard,
        private RestaurantNavigationContext $navigationContext,
        private TelegramMessageEditor $messageEditor,
    ) {
    }

    public function select(Nutgram $bot, int $restaurantId): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        try {
            $selection = $this->backend->selectCurrentSessionRestaurant($sessionToken, $restaurantId);
        } catch (OrderingBackendException $exception) {
            $this->handleSelectionFailure($bot, $exception, $sessionToken, $restaurantId);

            return;
        }

        $this->onboarding->editFulfillmentOptions(
            bot: $bot,
            sessionToken: $sessionToken,
            context: $this->navigationContext->encode($selection['restaurant']->id, $sessionToken),
        );
    }

    private function handleSelectionFailure(Nutgram $bot, OrderingBackendException $exception, string $sessionToken, int $restaurantId): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        if ($exception->statusCode() === 409) {
            $this->messageEditor->edit(
                bot: $bot,
                text: '🍽 Ресторан уже обрано для цієї сесії. Продовжимо з вибором способу отримання.',
                keyboard: $this->restaurantKeyboard->make([]),
            );
            $this->onboarding->renderFulfillmentOptions(
                bot: $bot,
                sessionToken: $sessionToken,
                context: $this->navigationContext->encode($restaurantId, $sessionToken),
            );

            return;
        }

        $message = match ($exception->statusCode()) {
            404 => '🍽 Обраний ресторан недоступний. Оберіть інший ресторан.',
            422 => '🍽 Не вдалося обрати ресторан. Спробуйте ще раз.',
            default => 'Не вдалося обрати ресторан. Спробуйте пізніше.',
        };

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->restaurantKeyboard->make([]),
        );
    }
}
