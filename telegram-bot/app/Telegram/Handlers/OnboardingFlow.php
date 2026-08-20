<?php

namespace App\Telegram\Handlers;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\Keyboards\CityKeyboard;
use App\Telegram\Keyboards\ExitKeyboard;
use App\Telegram\Keyboards\FulfillmentKeyboard;
use App\Telegram\Keyboards\OtpKeyboard;
use App\Telegram\Keyboards\RestaurantKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final class OnboardingFlow
{
    public function __construct(
        private readonly TelegramSessionRecovery $sessionRecovery,
        private readonly OrderingBackendClient $backend,
        private readonly OtpKeyboard $otpKeyboard,
        private readonly CityKeyboard $cityKeyboard,
        private readonly RestaurantKeyboard $restaurantKeyboard,
        private readonly FulfillmentKeyboard $fulfillmentKeyboard,
        private readonly ExitKeyboard $exitKeyboard,
        private readonly TelegramMessageEditor $messageEditor,
    ) {
    }

    public function requestOtp(Nutgram $bot, string $sessionToken): void
    {
        try {
            $otp = $this->backend->requestCurrentSessionOtp($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleOtpRequestFailure($bot, $exception);

            return;
        }

        $bot->sendMessage(
            text: '🔐 Код підтвердження надіслано. Введіть його повідомленням у цей чат.',
            reply_markup: $this->otpKeyboard->make(),
        );
        $bot->sendMessage(
            text: "🔐 Код підтвердження: {$otp['code']}\n\nВведіть його повідомленням у цей чат.",
        );
    }

    public function renderCities(Nutgram $bot, string $sessionToken): void
    {
        try {
            $cities = $this->backend->cities();
        } catch (OrderingBackendException $exception) {
            $this->handleGenericFailure($bot, $exception, 'Не вдалося отримати список міст. Спробуйте пізніше.');

            return;
        }

        if ($cities === []) {
            $bot->sendMessage(
                text: '🏙 Наразі немає доступних міст. Спробуйте пізніше.',
                reply_markup: $this->cityKeyboard->make([]),
            );

            return;
        }

        $bot->sendMessage(
            text: '🏙 Оберіть місто:',
            reply_markup: $this->cityKeyboard->make($cities),
        );
    }

    public function renderRestaurants(Nutgram $bot, string $sessionToken): void
    {
        try {
            $restaurants = $this->backend->currentSessionRestaurants($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleGenericFailure($bot, $exception, 'Не вдалося отримати список ресторанів. Спробуйте пізніше.');

            return;
        }

        if ($restaurants === []) {
            $bot->sendMessage(
                text: '🍽 У цьому місті поки немає доступних ресторанів.',
                reply_markup: $this->restaurantKeyboard->make([]),
            );

            return;
        }

        $bot->sendMessage(
            text: '🍽 Оберіть ресторан:',
            reply_markup: $this->restaurantKeyboard->make($restaurants),
        );
    }

    public function renderFulfillmentOptions(Nutgram $bot, string $sessionToken, string $context): void
    {
        try {
            $options = $this->backend->currentSessionFulfillmentOptions($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleGenericFailure($bot, $exception, 'Не вдалося підготувати способи отримання. Спробуйте пізніше.');

            return;
        }

        if ($options === []) {
            $bot->sendMessage(
                text: '📦 Для цього ресторану поки немає доступних способів отримання.',
                reply_markup: $this->fulfillmentKeyboard->make([], $context),
            );

            return;
        }

        $bot->sendMessage(
            text: '📦 Оберіть спосіб отримання замовлення:',
            reply_markup: $this->fulfillmentKeyboard->make($options, $context),
        );
    }

    public function editCities(Nutgram $bot, string $sessionToken): void
    {
        try {
            $cities = $this->backend->cities();
        } catch (OrderingBackendException $exception) {
            $this->handleGenericCallbackFailure($bot, $exception, 'Не вдалося отримати список міст. Спробуйте пізніше.');

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $cities === [] ? '🏙 Наразі немає доступних міст. Спробуйте пізніше.' : '🏙 Оберіть місто:',
            keyboard: $this->cityKeyboard->make($cities),
        );
    }

    public function editRestaurants(Nutgram $bot, string $sessionToken): void
    {
        try {
            $restaurants = $this->backend->currentSessionRestaurants($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleGenericCallbackFailure($bot, $exception, 'Не вдалося отримати список ресторанів. Спробуйте пізніше.');

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $restaurants === [] ? '🍽 У цьому місті поки немає доступних ресторанів.' : '🍽 Оберіть ресторан:',
            keyboard: $this->restaurantKeyboard->make($restaurants),
        );
    }

    public function editFulfillmentOptions(Nutgram $bot, string $sessionToken, string $context): void
    {
        try {
            $options = $this->backend->currentSessionFulfillmentOptions($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleGenericCallbackFailure($bot, $exception, 'Не вдалося підготувати способи отримання. Спробуйте пізніше.');

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $options === [] ? '📦 Для цього ресторану поки немає доступних способів отримання.' : '📦 Оберіть спосіб отримання замовлення:',
            keyboard: $this->fulfillmentKeyboard->make($options, $context),
        );
    }

    private function handleOtpRequestFailure(Nutgram $bot, OrderingBackendException $exception): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        $message = match ($exception->statusCode()) {
            409 => '⏳ Код уже надіслано. Спробуйте повторити запит трохи пізніше.',
            422 => 'Не вдалося надіслати код. Перевірте номер телефону та спробуйте ще раз.',
            default => 'Сервіс тимчасово недоступний. Спробуйте пізніше.',
        };

        $bot->sendMessage(
            text: $message,
            reply_markup: $this->otpKeyboard->make(),
        );
    }

    private function handleGenericFailure(Nutgram $bot, OrderingBackendException $exception, string $message): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        $bot->sendMessage(
            text: $message,
            reply_markup: $this->exitKeyboard->make(),
        );
    }

    private function handleGenericCallbackFailure(Nutgram $bot, OrderingBackendException $exception, string $message): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->exitKeyboard->make(),
        );
    }
}
