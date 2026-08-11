<?php

namespace App\Telegram\Handlers;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\CallbackAcknowledger;
use App\Telegram\Formatting\CartMessageFormatter;
use App\Telegram\Formatting\CheckoutMessageFormatter;
use App\Telegram\Formatting\OrderMessageFormatter;
use App\Telegram\Keyboards\CartKeyboard;
use App\Telegram\Keyboards\CheckoutKeyboard;
use App\Telegram\Keyboards\OrderKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\TelegramMessageEditor;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Nutgram;

final class CheckoutHandler
{
    private const RESTAURANT_HOURS_REJECTIONS = [
        'Компанiя не працює у вказаний в замовленні час',
        'Компанія не працює у вказаний в замовленні час',
        'Компания не работает в это время',
    ];

    public function __construct(
        private readonly CallbackAcknowledger $callbackAcknowledger,
        private readonly TelegramSessionRecovery $sessionRecovery,
        private readonly OrderingBackendClient $backend,
        private readonly CheckoutMessageFormatter $checkoutFormatter,
        private readonly CheckoutKeyboard $checkoutKeyboard,
        private readonly OrderMessageFormatter $orderFormatter,
        private readonly OrderKeyboard $orderKeyboard,
        private readonly CartMessageFormatter $cartFormatter,
        private readonly CartKeyboard $cartKeyboard,
        private readonly TelegramMessageEditor $messageEditor,
    ) {}

    public function checkout(Nutgram $bot): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        try {
            $cart = $this->backend->currentCart($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleCartFailure($bot, $exception);

            return;
        }

        if ($cart['items'] === [] || $cart['status'] !== 'active') {
            $this->renderCart($bot, $cart);

            return;
        }

        $idempotencyKey = (string) Str::uuid();

        $this->messageEditor->edit(
            bot: $bot,
            text: $this->checkoutFormatter->confirmation($cart),
            keyboard: $this->checkoutKeyboard->confirmation($idempotencyKey),
        );
    }

    public function confirm(Nutgram $bot, string $idempotencyKey): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        if (! Str::isUuid($idempotencyKey)) {
            $this->messageEditor->edit(
                bot: $bot,
                text: 'Не удалось подтвердить заказ. Вернитесь в корзину и повторите оформление.',
                keyboard: $this->orderKeyboard->backToCart(),
            );

            return;
        }

        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        try {
            $order = $this->backend->createOrder(
                sessionToken: $sessionToken,
                idempotencyKey: $idempotencyKey,
                deliveryTime: 0,
            );
        } catch (OrderingBackendException $exception) {
            $this->handleOrderCreationFailure($bot, $exception);

            return;
        }

        $this->ensureNextCart($bot, $sessionToken);
        $this->renderOrder($bot, $order);
    }

    public function cancel(Nutgram $bot): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        try {
            $cart = $this->backend->currentCart($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleCartFailure($bot, $exception);

            return;
        }

        $this->renderCart($bot, $cart);
    }

    public function refresh(Nutgram $bot): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        try {
            $order = $this->backend->currentOrder($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleCurrentOrderFailure($bot, $exception);

            return;
        }

        $this->renderOrder($bot, $order);
    }

    /**
     * @param  array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}  $cart
     */
    private function renderCart(Nutgram $bot, array $cart): void
    {
        $this->messageEditor->edit(
            bot: $bot,
            text: $this->cartFormatter->format($cart),
            keyboard: $this->cartKeyboard->make($cart['items'], $cart['status']),
        );
    }

    /**
     * @param  array{id: int, external_order_id: ?string, status: string, failure_message: ?string, receiving_type: string, total: string, currency: string, items: list<array{product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}  $order
     */
    private function renderOrder(Nutgram $bot, array $order): void
    {
        $this->messageEditor->edit(
            bot: $bot,
            text: $this->orderFormatter->format($order),
            keyboard: $this->orderKeyboard->make($order['status']),
        );
    }

    private function handleCartFailure(Nutgram $bot, OrderingBackendException $exception): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        $message = match ($exception->statusCode()) {
            404 => 'Корзина недоступна.',
            409, 422 => 'Не удалось подготовить оформление заказа.',
            default => 'Сервис корзины временно недоступен. Попробуйте снова позже.',
        };

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->cartKeyboard->make(),
        );
    }

    private function handleOrderCreationFailure(Nutgram $bot, OrderingBackendException $exception): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        if ($exception->statusCode() === 422) {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->unprocessableOrderMessage($exception),
                keyboard: $this->orderKeyboard->backToCart(),
            );

            return;
        }

        if ($exception->statusCode() === 404) {
            $this->messageEditor->edit(
                bot: $bot,
                text: 'Не удалось найти активную корзину для оформления заказа.',
                keyboard: $this->orderKeyboard->backToCart(),
            );

            return;
        }

        $message = $exception->statusCode() === 409
            ? 'Заказ уже оформляется или корзина изменилась. Проверьте текущий заказ.'
            : 'Не удалось однозначно определить результат оформления. Проверьте статус заказа перед повторной попыткой.';

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->orderKeyboard->statusCheck(),
        );
    }

    private function unprocessableOrderMessage(OrderingBackendException $exception): string
    {
        $responseMessage = $exception->responseMessage();

        if ($responseMessage !== null && in_array(
            Str::squish($responseMessage),
            self::RESTAURANT_HOURS_REJECTIONS,
            true,
        )) {
            return 'Сейчас ресторан не принимает заказы. Попробуйте оформить заказ в рабочее время.';
        }

        return 'Не удалось оформить заказ. Проверьте корзину и контактные данные.';
    }

    private function handleCurrentOrderFailure(Nutgram $bot, OrderingBackendException $exception): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        if ($exception->statusCode() === 404) {
            $this->messageEditor->edit(
                bot: $bot,
                text: 'Текущий заказ не найден.',
                keyboard: $this->orderKeyboard->backToCart(),
            );

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: 'Не удалось проверить статус заказа. Попробуйте снова позже.',
            keyboard: $this->orderKeyboard->statusCheck(),
        );
    }

    private function ensureNextCart(Nutgram $bot, string $sessionToken): void
    {
        try {
            $this->backend->ensureCurrentCart($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->sessionRecovery->recoverIfUnauthorized($bot, $exception);
        }
    }
}
