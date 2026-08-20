<?php

namespace App\Telegram\Fulfillment;

use App\DTO\OrderingBackend\DeliveryValidationData;
use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\Keyboards\DeliveryAddressKeyboard;
use App\Telegram\Keyboards\MainMenuKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\Support\DeliveryAddressParser;
use App\Telegram\Support\DeliveryAddressPromptStore;
use App\Telegram\Support\RestaurantNavigationContext;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ForceReply;

final readonly class DeliveryAddressFlow
{
    public function __construct(
        private TelegramSessionRecovery $sessionRecovery,
        private OrderingBackendClient $backend,
        private DeliveryAddressParser $addressParser,
        private DeliveryAddressPromptStore $addressPromptStore,
        private RestaurantNavigationContext $navigationContext,
        private DeliveryAddressKeyboard $deliveryAddressKeyboard,
        private MainMenuKeyboard $mainMenuKeyboard,
        private TelegramMessageEditor $messageEditor,
        private FulfillmentContextResolver $contextResolver,
    ) {
    }

    public function askType(
        Nutgram $bot,
        string $context,
    ): void {
        $bot->sendMessage(
            text: '📍 Оберіть тип адреси доставки:',
            reply_markup: $this->deliveryAddressKeyboard->types($context),
        );
    }

    public function startForType(
        Nutgram $bot,
        int $type,
        int $restaurantId,
        string $sessionToken,
        string $callbackContext,
    ): void {
        if (! in_array($type, [0, 1, 2, 3], true)) {
            $this->messageEditor->edit(
                bot: $bot,
                text: 'Не вдалося обрати тип адреси. Спробуйте ще раз.',
                keyboard: $this->deliveryAddressKeyboard->types(
                    $callbackContext,
                ),
            );

            return;
        }

        $this->askAddress(
            $bot,
            $type,
            $restaurantId,
            $sessionToken,
        );
    }

    public function handleAddress(
        Nutgram $bot,
        string $address,
    ): void {
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
        $restaurantId = $promptContext['restaurant_id'];

        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        if (! $this->navigationContext->isValid(
            $restaurantId,
            $promptContext['fingerprint'],
            $sessionToken,
        )) {
            $bot->sendMessage(
                text: '⚠️ Це старий запит адреси. Почніть вибір доставки ще раз.',
                reply_markup: $this->deliveryAddressKeyboard->types(
                    $this->navigationContext->encode(
                        $restaurantId,
                        $sessionToken,
                    ),
                ),
            );

            return;
        }

        if (! $this->contextResolver->restaurantBelongsToCurrentSession(
            $bot,
            $restaurantId,
            $sessionToken,
        )) {
            $bot->sendMessage(
                text: RestaurantNavigationContext::STALE_MESSAGE,
                reply_markup: $this->mainMenuKeyboard->make(
                    $this->navigationContext->encode(
                        $restaurantId,
                        $sessionToken,
                    ),
                ),
            );

            return;
        }

        $callbackContext = $this->navigationContext->encode(
            $restaurantId,
            $sessionToken,
        );

        $payload = $this->addressParser->parse(
            $address,
            $type,
        );

        if ($payload === null) {
            $this->askAddress(
                bot: $bot,
                type: $type,
                restaurantId: $restaurantId,
                sessionToken: $sessionToken,
                message: "❌ Не вдалося розпізнати адресу.\n\nНадішліть її у форматі:\n{$this->addressFormat($type)}",
            );

            return;
        }

        try {
            $result = $this->backend->validateCurrentSessionDeliveryAddress(
                $sessionToken,
                $payload,
            );
        } catch (OrderingBackendException $exception) {
            $this->handleAddressFailure(
                bot: $bot,
                exception: $exception,
                type: $type,
                restaurantId: $restaurantId,
                sessionToken: $sessionToken,
            );

            return;
        }

        if ($result->deliveryAvailable !== true) {
            $this->renderDeliveryUnavailable(
                $bot,
                $result,
                $callbackContext,
            );

            return;
        }

        $this->renderDeliveryAvailable(
            $bot,
            $result,
            $callbackContext,
        );
    }

    private function askAddress(
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
            fingerprint: $this->navigationContext->fingerprint(
                $sessionToken,
            ),
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

    private function renderDeliveryAvailable(
        Nutgram $bot,
        DeliveryValidationData $result,
        string $context,
    ): void {
        $lines = ['✅ Доставка доступна'];

        $address = $this->trustedAddress(
            $result->fulfillment,
        );

        if ($address !== null) {
            $lines[] = "📍 Адреса: {$address}";
        }

        if (
            $result->deliveryPrice !== null
            && $result->deliveryPrice !== ''
        ) {
            /** @var bool|float|int|string $deliveryPrice */
            $deliveryPrice = $result->deliveryPrice;

            $lines[] = "💰 Вартість доставки: {$deliveryPrice}";
        }

        $bot->sendMessage(
            text: implode("\n", $lines),
            reply_markup: $this->mainMenuKeyboard->make(
                $context,
            ),
        );
    }

    private function renderDeliveryUnavailable(
        Nutgram $bot,
        DeliveryValidationData $result,
        string $context,
    ): void {
        $message = match ($result->reason) {
            'outside_delivery_zone' => '❌ Цей ресторан не доставляє за вказаною адресою.',

            'invalid_address' => "❌ Не вдалося підтвердити адресу.\nПеревірте назву вулиці та номер будинку й спробуйте ще раз.",

            default => '❌ Доставка за цією адресою недоступна.',
        };

        $keyboard = $result->reason === 'invalid_address'
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
    private function trustedAddress(
        array $fulfillment,
    ): ?string {
        $address = $fulfillment['delivery_address'] ?? null;

        if (
            is_string($address)
            && trim($address) !== ''
        ) {
            return trim($address);
        }

        if (! is_array($address)) {
            return null;
        }

        foreach (
            ['title', 'address', 'formatted_address']
            as $field
        ) {
            if (
                is_string($address[$field] ?? null)
                && trim($address[$field]) !== ''
            ) {
                return trim($address[$field]);
            }
        }

        $parts = array_filter(
            [
                $address['street'] ?? null,
                $address['house'] ?? null,
                $address['flat'] ?? null,
            ],
            static fn (mixed $part): bool => is_string($part)
                && trim($part) !== '',
        );

        return $parts === []
            ? null
            : implode(', ', $parts);
    }

    private function handleAddressFailure(
        Nutgram $bot,
        OrderingBackendException $exception,
        int $type,
        int $restaurantId,
        string $sessionToken,
    ): void {
        if ($this->sessionRecovery->recoverIfUnauthorized(
            $bot,
            $exception,
        )) {
            return;
        }

        $context = $this->navigationContext->encode(
            $restaurantId,
            $sessionToken,
        );

        if ($exception->statusCode() === 409) {
            $bot->sendMessage(
                text: '⚠️ Спосіб отримання вже не можна змінити для цього замовлення.',
                reply_markup: $this->mainMenuKeyboard->make(
                    $context,
                ),
            );

            return;
        }

        if ($exception->statusCode() === 422) {
            $this->askAddress(
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
            reply_markup: $this->deliveryAddressKeyboard
                ->serviceUnavailable(
                    $context,
                ),
        );
    }
}
