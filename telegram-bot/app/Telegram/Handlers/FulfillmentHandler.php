<?php

namespace App\Telegram\Handlers;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\CallbackAcknowledger;
use App\Telegram\Keyboards\DeliveryAddressKeyboard;
use App\Telegram\Keyboards\FulfillmentKeyboard;
use App\Telegram\Keyboards\MainMenuKeyboard;
use App\Telegram\Keyboards\PickupAddressKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\Support\DeliveryAddressParser;
use App\Telegram\Support\RestaurantNavigationContext;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ForceReply;
use App\Telegram\Support\DeliveryAddressPromptStore;

final class FulfillmentHandler
{
    public function __construct(
        private readonly CallbackAcknowledger $callbackAcknowledger,
        private readonly TelegramSessionRecovery $sessionRecovery,
        private readonly OrderingBackendClient $backend,
        private readonly DeliveryAddressParser $addressParser,
        private readonly DeliveryAddressPromptStore $addressPromptStore,
        private readonly RestaurantNavigationContext $navigationContext,
        private readonly FulfillmentKeyboard $fulfillmentKeyboard,
        private readonly DeliveryAddressKeyboard $deliveryAddressKeyboard,
        private readonly PickupAddressKeyboard $pickupAddressKeyboard,
        private readonly MainMenuKeyboard $mainMenuKeyboard,
        private readonly TelegramMessageEditor $messageEditor,
    ) {}

    public function menu(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->validatedContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        $this->renderFulfillmentOptions($bot, $context['sessionToken'], $context['callbackContext']);
    }

    public function delivery(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->validatedContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        if (! $this->selectFulfillment($bot, $context['sessionToken'], 'delivery', $context['callbackContext'])) {
            return;
        }

        $this->askDeliveryType($bot, $context['callbackContext']);
    }

    public function pickup(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->validatedContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        if (! $this->selectFulfillment($bot, $context['sessionToken'], 'pickup', $context['callbackContext'])) {
            return;
        }

        $this->renderPickupAddresses($bot, $context['sessionToken'], $context['callbackContext']);
    }

    public function retryDeliveryAddress(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->validatedContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        $this->askDeliveryType($bot, $context['callbackContext']);
    }

    public function deliveryAddressType(Nutgram $bot, int $type, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->validatedContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        if (! in_array($type, [0, 1, 2, 3], true)) {
            $this->messageEditor->edit(
                bot: $bot,
                text: 'Не вдалося обрати тип адреси. Спробуйте ще раз.',
                keyboard: $this->deliveryAddressKeyboard->types($context['callbackContext']),
            );

            return;
        }

        $this->askDeliveryAddress($bot, $type, $restaurantId, $context['sessionToken']);
    }

    public function address(Nutgram $bot, string $address): void
    {
        $replyMessage = $bot->message()?->reply_to_message;
        $chatId = $bot->chatId();

        if ($replyMessage === null || $chatId === null) {
            return;
        }

        $promptContext = $this->addressPromptStore->get(
            $chatId,
            $replyMessage->message_id,
        );

        if ($promptContext === null) {
            return;
        }

        $type = $promptContext['type'];
        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        if (! $this->navigationContext->isValid($promptContext['restaurant_id'], $promptContext['fingerprint'], $sessionToken)) {
            $bot->sendMessage(
                text: '⚠️ Це старий запит адреси. Почніть вибір доставки ще раз.',
                reply_markup: $this->deliveryAddressKeyboard->types($this->navigationContext->encode($promptContext['restaurant_id'], $sessionToken)),
            );

            return;
        }

        if (! $this->restaurantBelongsToCurrentSession($bot, $promptContext['restaurant_id'], $sessionToken)) {
            $bot->sendMessage(
                text: RestaurantNavigationContext::STALE_MESSAGE,
                reply_markup: $this->mainMenuKeyboard->make($this->navigationContext->encode($promptContext['restaurant_id'], $sessionToken)),
            );

            return;
        }

        $callbackContext = $this->navigationContext->encode($promptContext['restaurant_id'], $sessionToken);
        $payload = $this->addressParser->parse($address, $type);

        if ($payload === null) {
            $this->askDeliveryAddress(
                bot: $bot,
                type: $type,
                restaurantId: $promptContext['restaurant_id'],
                sessionToken: $sessionToken,
                message: "❌ Не вдалося розпізнати адресу.\n\nНадішліть її у форматі:\n{$this->addressFormat($type)}",
            );

            return;
        }

        try {
            $result = $this->backend->validateCurrentSessionDeliveryAddress($sessionToken, $payload);
        } catch (OrderingBackendException $exception) {
            $this->handleAddressFailure(
                bot: $bot,
                exception: $exception,
                type: $type,
                restaurantId: $promptContext['restaurant_id'],
                sessionToken: $sessionToken,
            );

            return;
        }

        if ($result['delivery_available'] !== true) {
            $this->renderDeliveryUnavailable($bot, $result, $callbackContext);

            return;
        }

        $this->renderDeliveryAvailable($bot, $result, $callbackContext);
    }

    public function selectPickupAddress(Nutgram $bot, int $restaurantAddressId, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->validatedContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        try {
            $this->backend->selectCurrentSessionPickupAddress($context['sessionToken'], $restaurantAddressId);
        } catch (OrderingBackendException $exception) {
            $this->handlePickupAddressFailure($bot, $exception, $context['callbackContext']);

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: "✅ Самовивіз обрано.\n📍 Адресу самовивозу збережено.",
            keyboard: $this->mainMenuKeyboard->make($context['callbackContext']),
        );
    }

    private function renderFulfillmentOptions(Nutgram $bot, string $sessionToken, string $context): void
    {
        try {
            $options = $this->backend->currentSessionFulfillmentOptions($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleGenericCallbackFailure($bot, $exception, 'Не вдалося підготувати способи отримання. Спробуйте пізніше.', $context);

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $options === [] ? '📦 Для цього ресторану поки немає доступних способів отримання.' : '📦 Оберіть спосіб отримання замовлення:',
            keyboard: $this->fulfillmentKeyboard->make($options, $context),
        );
    }

    private function selectFulfillment(Nutgram $bot, string $sessionToken, string $type, string $context): bool
    {
        try {
            $this->backend->selectCurrentSessionFulfillment($sessionToken, $type);
        } catch (OrderingBackendException $exception) {
            $this->handleFulfillmentSelectionFailure($bot, $exception, $context);

            return false;
        }

        return true;
    }

    private function askDeliveryType(Nutgram $bot, string $context): void
    {
        $bot->sendMessage(
            text: '📍 Оберіть тип адреси доставки:',
            reply_markup: $this->deliveryAddressKeyboard->types($context),
        );
    }

    private function askDeliveryAddress(
        Nutgram $bot,
        int $type,
        int $restaurantId,
        string $sessionToken,
        ?string $message = null,
    ): void {
        $sentMessage = $bot->sendMessage(
            text: $message ?? $this->addressPrompt($type),
            reply_markup: ForceReply::make(
                force_reply: true,
                input_field_placeholder: 'Вулиця, будинок, квартира',
                selective: true,
            ),
        );

        $chatId = $bot->chatId();

        if ($sentMessage === null || $chatId === null) {
            return;
        }

        $this->addressPromptStore->put(
            chatId: $chatId,
            messageId: $sentMessage->message_id,
            type: $type,
            restaurantId: $restaurantId,
            fingerprint: $this->navigationContext->fingerprint($sessionToken),
        );
    }

    private function addressPrompt(int $type): string
    {
        $format = $this->addressFormat($type);
        $example = $type === 0
            ? "\n\nНаприклад:\nШевченка, 11А, 7"
            : '';

        return implode("\n\n", [
            $this->addressTypeCaption($type),
            "📍 Введіть адресу у форматі:\n{$format}{$example}",
        ]);
    }

    private function addressTypeCaption(int $type): string
    {
        return match ($type) {
            0 => '🏢 Квартира',
            1 => '🏠 Приватний будинок',
            2 => '🏢 Офіс',
            default => '📍 Інше',
        };
    }

    private function addressFormat(int $type): string
    {
        return $type === 0
            ? 'Вулиця, будинок, квартира'
            : 'Вулиця, будинок';
    }

    private function renderPickupAddresses(Nutgram $bot, string $sessionToken, string $context): void
    {
        try {
            $addresses = $this->backend->currentSessionPickupAddresses($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleGenericCallbackFailure($bot, $exception, 'Не вдалося отримати адреси самовивозу. Спробуйте пізніше.', $context);

            return;
        }

        if ($addresses === []) {
            $this->messageEditor->edit(
                bot: $bot,
                text: '❌ Для цього ресторану зараз немає доступних точок самовивозу.',
                keyboard: $this->pickupAddressKeyboard->make([], $context),
            );

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: '📍 Оберіть точку самовивозу:',
            keyboard: $this->pickupAddressKeyboard->make($addresses, $context),
        );
    }

    /**
     * @param  array{delivery_available: bool, reason: ?string, delivery_price: mixed, fulfillment: array<string, mixed>}  $result
     */
    private function renderDeliveryAvailable(Nutgram $bot, array $result, string $context): void
    {
        $lines = ['✅ Доставка доступна'];
        $address = $this->trustedAddress($result['fulfillment']);

        if ($address !== null) {
            $lines[] = "📍 Адреса: {$address}";
        }

        if ($result['delivery_price'] !== null && $result['delivery_price'] !== '') {
            $lines[] = "💰 Вартість доставки: {$result['delivery_price']}";
        }

        $bot->sendMessage(
            text: implode("\n", $lines),
            reply_markup: $this->mainMenuKeyboard->make($context),
        );
    }

    /**
     * @param  array{reason: ?string}  $result
     */
    private function renderDeliveryUnavailable(Nutgram $bot, array $result, string $context): void
    {
        $message = match ($result['reason']) {
            'outside_delivery_zone' => '❌ Цей ресторан не доставляє за вказаною адресою.',
            'invalid_address' => "❌ Не вдалося підтвердити адресу.\nПеревірте назву вулиці та номер будинку й спробуйте ще раз.",
            default => '❌ Доставка за цією адресою недоступна.',
        };

        $keyboard = $result['reason'] === 'invalid_address'
            ? $this->deliveryAddressKeyboard->retry($context)
            : $this->deliveryAddressKeyboard->unavailable($context);

        $bot->sendMessage(
            text: $message,
            reply_markup: $keyboard,
        );
    }

    /**
     * @param  array<string, mixed>  $fulfillment
     */
    private function trustedAddress(array $fulfillment): ?string
    {
        $address = $fulfillment['delivery_address'] ?? null;

        if (is_string($address) && trim($address) !== '') {
            return trim($address);
        }

        if (! is_array($address)) {
            return null;
        }

        foreach (['title', 'address', 'formatted_address'] as $field) {
            if (is_string($address[$field] ?? null) && trim($address[$field]) !== '') {
                return trim($address[$field]);
            }
        }

        $parts = array_filter([
            $address['street'] ?? null,
            $address['house'] ?? null,
            $address['flat'] ?? null,
        ], static fn (mixed $part): bool => is_string($part) && trim($part) !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function handleFulfillmentSelectionFailure(Nutgram $bot, OrderingBackendException $exception, string $context): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
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
            keyboard: $this->mainMenuKeyboard->make($context),
        );
    }

    private function handleAddressFailure(
        Nutgram $bot,
        OrderingBackendException $exception,
        int $type,
        int $restaurantId,
        string $sessionToken,
    ): void {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        $context = $this->navigationContext->encode(
            $restaurantId,
            $sessionToken,
        );

        if ($exception->statusCode() === 409) {
            $bot->sendMessage(
                text: '⚠️ Спосіб отримання вже не можна змінити для цього замовлення.',
                reply_markup: $this->mainMenuKeyboard->make($context),
            );

            return;
        }

        if ($exception->statusCode() === 422) {
            $this->askDeliveryAddress(
                bot: $bot,
                type: $type,
                restaurantId: $restaurantId,
                sessionToken: $sessionToken,
                message: "❌ Не вдалося підтвердити адресу.\nПеревірте назву вулиці та номер будинку й спробуйте ще раз.",
            );

            return;
        }

        $bot->sendMessage(
            text: '⚠️ Сервіс тимчасово недоступний. Спробуйте ще раз трохи пізніше або оберіть самовивіз.',
            reply_markup: $this->deliveryAddressKeyboard->serviceUnavailable($context),
        );
    }

    private function handlePickupAddressFailure(Nutgram $bot, OrderingBackendException $exception, string $context): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
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
            keyboard: $this->mainMenuKeyboard->make($context),
        );
    }

    private function handleGenericCallbackFailure(Nutgram $bot, OrderingBackendException $exception, string $message, string $context): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->mainMenuKeyboard->make($context),
        );
    }

    /**
     * @return array{sessionToken: string, callbackContext: string}|null
     */
    private function validatedContext(Nutgram $bot, int $restaurantId, string $fingerprint): ?array
    {
        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return null;
        }

        if (! $this->navigationContext->isValid($restaurantId, $fingerprint, $sessionToken)) {
            $this->messageEditor->edit(
                bot: $bot,
                text: RestaurantNavigationContext::STALE_MESSAGE,
                keyboard: $this->mainMenuKeyboard->make($this->navigationContext->encode($restaurantId, $sessionToken)),
            );

            return null;
        }

        try {
            $restaurants = $this->backend->currentSessionRestaurants($sessionToken);
        } catch (OrderingBackendException $exception) {
            if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
                return null;
            }

            $this->messageEditor->edit(
                bot: $bot,
                text: RestaurantNavigationContext::STALE_MESSAGE,
                keyboard: $this->mainMenuKeyboard->make($this->navigationContext->encode($restaurantId, $sessionToken)),
            );

            return null;
        }

        foreach ($restaurants as $restaurant) {
            if ($restaurant['id'] === $restaurantId) {
                return [
                    'sessionToken' => $sessionToken,
                    'callbackContext' => $this->navigationContext->encode($restaurantId, $sessionToken),
                ];
            }
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: RestaurantNavigationContext::STALE_MESSAGE,
            keyboard: $this->mainMenuKeyboard->make($this->navigationContext->encode($restaurantId, $sessionToken)),
        );

        return null;
    }

    private function restaurantBelongsToCurrentSession(Nutgram $bot, int $restaurantId, string $sessionToken): bool
    {
        try {
            $restaurants = $this->backend->currentSessionRestaurants($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->sessionRecovery->recoverIfUnauthorized($bot, $exception);

            return false;
        }

        foreach ($restaurants as $restaurant) {
            if ($restaurant['id'] === $restaurantId) {
                return true;
            }
        }

        return false;
    }
}
