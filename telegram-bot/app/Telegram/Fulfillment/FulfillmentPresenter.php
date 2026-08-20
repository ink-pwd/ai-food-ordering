<?php

namespace App\Telegram\Fulfillment;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\Keyboards\FulfillmentKeyboard;
use App\Telegram\Keyboards\MainMenuKeyboard;
use App\Telegram\Keyboards\PickupAddressKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final readonly class FulfillmentPresenter
{
    public function __construct(
        private TelegramSessionRecovery $sessionRecovery,
        private OrderingBackendClient $backend,
        private FulfillmentKeyboard $fulfillmentKeyboard,
        private PickupAddressKeyboard $pickupAddressKeyboard,
        private MainMenuKeyboard $mainMenuKeyboard,
        private TelegramMessageEditor $messageEditor,
    ) {
    }

    public function renderOptions(
        Nutgram $bot,
        string $sessionToken,
        string $context,
    ): void {
        try {
            $options = $this->backend
                ->currentSessionFulfillmentOptions(
                    $sessionToken,
                );
        } catch (OrderingBackendException $exception) {
            $this->handleGenericCallbackFailure(
                $bot,
                $exception,
                'Не вдалося підготувати способи отримання. Спробуйте пізніше.',
                $context,
            );

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $options === []
                ? '📦 Для цього ресторану поки немає доступних способів отримання.'
                : '📦 Оберіть спосіб отримання замовлення:',
            keyboard: $this->fulfillmentKeyboard->make(
                $options,
                $context,
            ),
        );
    }

    public function select(
        Nutgram $bot,
        string $sessionToken,
        string $type,
        string $context,
    ): bool {
        try {
            $this->backend->selectCurrentSessionFulfillment(
                $sessionToken,
                $type,
            );
        } catch (OrderingBackendException $exception) {
            $this->handleFulfillmentSelectionFailure(
                $bot,
                $exception,
                $context,
            );

            return false;
        }

        return true;
    }

    public function renderPickupAddresses(
        Nutgram $bot,
        string $sessionToken,
        string $context,
    ): void {
        try {
            $addresses = $this->backend
                ->currentSessionPickupAddresses(
                    $sessionToken,
                );
        } catch (OrderingBackendException $exception) {
            $this->handleGenericCallbackFailure(
                $bot,
                $exception,
                'Не вдалося отримати адреси самовивозу. Спробуйте пізніше.',
                $context,
            );

            return;
        }

        if ($addresses === []) {
            $this->messageEditor->edit(
                bot: $bot,
                text: '❌ Для цього ресторану зараз немає доступних точок самовивозу.',
                keyboard: $this->pickupAddressKeyboard->make(
                    [],
                    $context,
                ),
            );

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: '📍 Оберіть точку самовивозу:',
            keyboard: $this->pickupAddressKeyboard->make(
                $addresses,
                $context,
            ),
        );
    }

    public function selectPickupAddress(
        Nutgram $bot,
        string $sessionToken,
        int $restaurantAddressId,
        string $context,
    ): void {
        try {
            $this->backend->selectCurrentSessionPickupAddress(
                $sessionToken,
                $restaurantAddressId,
            );
        } catch (OrderingBackendException $exception) {
            $this->handlePickupAddressFailure(
                $bot,
                $exception,
                $context,
            );

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: "✅ Самовивіз обрано.\n📍 Адресу самовивозу збережено.",
            keyboard: $this->mainMenuKeyboard->make(
                $context,
            ),
        );
    }

    private function handleFulfillmentSelectionFailure(
        Nutgram $bot,
        OrderingBackendException $exception,
        string $context,
    ): void {
        if ($this->sessionRecovery->recoverIfUnauthorized(
            $bot,
            $exception,
        )) {
            return;
        }

        $message = match ($exception->statusCode()) {
            409 => '⚠️ Спосіб отримання вже не можна змінити для цього замовлення.',

            422 => 'Не вдалося обрати спосіб отримання. Спробуйте ще раз.',

            default => '⚠️ Сервіс тимчасово недоступний. Спробуйте ще раз трохи пізніше.',
        };

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->mainMenuKeyboard->make(
                $context,
            ),
        );
    }

    private function handlePickupAddressFailure(
        Nutgram $bot,
        OrderingBackendException $exception,
        string $context,
    ): void {
        if ($this->sessionRecovery->recoverIfUnauthorized(
            $bot,
            $exception,
        )) {
            return;
        }

        $message = match ($exception->statusCode()) {
            404 => '📍 Обрана точка самовивозу недоступна.',

            409 => '⚠️ Спосіб отримання вже не можна змінити для цього замовлення.',

            422 => 'Не вдалося обрати точку самовивозу. Спробуйте ще раз.',

            default => '⚠️ Сервіс тимчасово недоступний. Спробуйте ще раз трохи пізніше.',
        };

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->mainMenuKeyboard->make(
                $context,
            ),
        );
    }

    private function handleGenericCallbackFailure(
        Nutgram $bot,
        OrderingBackendException $exception,
        string $message,
        string $context,
    ): void {
        if ($this->sessionRecovery->recoverIfUnauthorized(
            $bot,
            $exception,
        )) {
            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->mainMenuKeyboard->make(
                $context,
            ),
        );
    }
}
