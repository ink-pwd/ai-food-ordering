<?php

namespace App\Telegram\Handlers;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\CallbackAcknowledger;
use App\Telegram\Formatting\CartMessageFormatter;
use App\Telegram\Keyboards\CartKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final class CartHandler
{
    public function __construct(
        private readonly CallbackAcknowledger $callbackAcknowledger,
        private readonly TelegramSessionRecovery $sessionRecovery,
        private readonly OrderingBackendClient $backend,
        private readonly CartMessageFormatter $formatter,
        private readonly CartKeyboard $keyboard,
        private readonly TelegramMessageEditor $messageEditor,
    ) {}

    public function show(Nutgram $bot): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        try {
            $cart = $this->backend->getOrCreateCurrentCart($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleBackendFailure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Корзина недоступна.',
                unprocessableMessage: 'Не удалось открыть корзину.',
            );

            return;
        }

        $this->renderCart($bot, $cart);
    }

    public function add(Nutgram $bot, int $productId): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        try {
            $cart = $this->backend->getOrCreateCurrentCart($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleBackendFailure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Корзина недоступна.',
                unprocessableMessage: 'Не удалось подготовить корзину.',
            );

            return;
        }

        $existingCartItem = $this->findCartItemByProductId($cart['items'], $productId);

        try {
            $cart = $existingCartItem === null
                ? $this->backend->addCurrentCartItem(
                    sessionToken: $sessionToken,
                    productId: $productId,
                    quantity: 1,
                )
                : $this->backend->updateCurrentCartItem(
                    sessionToken: $sessionToken,
                    itemId: $existingCartItem['id'],
                    quantity: $existingCartItem['quantity'] + 1,
                );
        } catch (OrderingBackendException $exception) {
            if ($existingCartItem === null && $exception->statusCode() === 409) {
                $this->messageEditor->edit(
                    bot: $bot,
                    text: 'Этот товар уже есть в корзине.',
                    keyboard: $this->keyboard->duplicateProduct(),
                );

                return;
            }

            $this->handleBackendFailure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Товар не найден.',
                unprocessableMessage: 'Не удалось добавить товар в корзину.',
            );

            return;
        }

        $this->renderCart($bot, $cart);
    }

    public function increment(Nutgram $bot, int $itemId): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $this->changeQuantity($bot, $itemId, 1);
    }

    public function decrement(Nutgram $bot, int $itemId): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $this->changeQuantity($bot, $itemId, -1);
    }

    public function remove(Nutgram $bot, int $itemId): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        $cart = $this->currentCartOrReport($bot, $sessionToken);

        if ($cart === null) {
            return;
        }

        $cartItem = $this->findCartItemById($cart['items'], $itemId);

        if ($cartItem === null) {
            $this->renderCartWithMissingItemNotice($bot, $cart);

            return;
        }

        $this->removeCartItem($bot, $sessionToken, $cartItem['id']);
    }

    public function clear(Nutgram $bot): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: 'Очистить всю корзину?',
            keyboard: $this->keyboard->clearConfirmation(),
        );
    }

    public function confirmClear(Nutgram $bot): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        try {
            $cart = $this->backend->clearCurrentCart($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleBackendFailure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Корзина недоступна.',
                unprocessableMessage: 'Не удалось очистить корзину.',
            );

            return;
        }

        $this->renderCart($bot, $cart);
    }

    public function cancelClear(Nutgram $bot): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        $cart = $this->currentCartOrReport($bot, $sessionToken);

        if ($cart === null) {
            return;
        }

        $this->renderCart($bot, $cart);
    }

    public function noop(Nutgram $bot, int $itemId): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }
    }

    /**
     * @param  array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}  $cart
     */
    private function renderCart(Nutgram $bot, array $cart): void
    {
        $this->messageEditor->edit(
            bot: $bot,
            text: $this->formatter->format($cart),
            keyboard: $this->keyboard->make($cart['items'], $cart['status']),
        );
    }

    private function changeQuantity(Nutgram $bot, int $itemId, int $difference): void
    {
        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        $cart = $this->currentCartOrReport($bot, $sessionToken);

        if ($cart === null) {
            return;
        }

        $cartItem = $this->findCartItemById($cart['items'], $itemId);

        if ($cartItem === null) {
            $this->renderCartWithMissingItemNotice($bot, $cart);

            return;
        }

        $quantity = $cartItem['quantity'] + $difference;

        if ($quantity < 1) {
            $this->removeCartItem($bot, $sessionToken, $cartItem['id']);

            return;
        }

        try {
            $updatedCart = $this->backend->updateCurrentCartItem(
                sessionToken: $sessionToken,
                itemId: $cartItem['id'],
                quantity: $quantity,
            );
        } catch (OrderingBackendException $exception) {
            if ($this->renderAfterMissingItemMutation($bot, $sessionToken, $exception)) {
                return;
            }

            $this->handleBackendFailure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Товар не найден в корзине.',
                unprocessableMessage: 'Не удалось изменить количество товара.',
            );

            return;
        }

        $this->renderCart($bot, $updatedCart);
    }

    /**
     * @return array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}|null
     */
    private function currentCartOrReport(Nutgram $bot, string $sessionToken): ?array
    {
        try {
            return $this->backend->currentCart($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleBackendFailure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Корзина недоступна.',
                unprocessableMessage: 'Не удалось обновить корзину.',
            );

            return null;
        }
    }

    private function removeCartItem(Nutgram $bot, string $sessionToken, int $itemId): void
    {
        try {
            $cart = $this->backend->removeCurrentCartItem($itemId, $sessionToken);
        } catch (OrderingBackendException $exception) {
            if ($this->renderAfterMissingItemMutation($bot, $sessionToken, $exception)) {
                return;
            }

            $this->handleBackendFailure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Товар уже отсутствует в корзине.',
                unprocessableMessage: 'Не удалось удалить товар из корзины.',
            );

            return;
        }

        $this->renderCart($bot, $cart);
    }

    private function renderAfterMissingItemMutation(
        Nutgram $bot,
        string $sessionToken,
        OrderingBackendException $exception,
    ): bool {
        if ($exception->statusCode() !== 404) {
            return false;
        }

        $cart = $this->currentCartOrReport($bot, $sessionToken);

        if ($cart !== null) {
            $this->renderCartWithMissingItemNotice($bot, $cart);
        }

        return true;
    }

    /**
     * @param  array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}  $cart
     */
    private function renderCartWithMissingItemNotice(Nutgram $bot, array $cart): void
    {
        $this->messageEditor->edit(
            bot: $bot,
            text: $this->formatter->formatWithNotice($cart, 'Товар уже отсутствует в корзине.'),
            keyboard: $this->keyboard->make($cart['items'], $cart['status']),
        );
    }

    /**
     * @param  list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>  $items
     * @return array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}|null
     */
    private function findCartItemByProductId(array $items, int $productId): ?array
    {
        foreach ($items as $cartItem) {
            if ($cartItem['product_id'] === $productId) {
                return $cartItem;
            }
        }

        return null;
    }

    /**
     * @param  list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>  $items
     * @return array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}|null
     */
    private function findCartItemById(array $items, int $itemId): ?array
    {
        foreach ($items as $cartItem) {
            if ($cartItem['id'] === $itemId) {
                return $cartItem;
            }
        }

        return null;
    }

    private function handleBackendFailure(
        Nutgram $bot,
        OrderingBackendException $exception,
        string $notFoundMessage,
        string $unprocessableMessage,
    ): void {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        $message = match ($exception->statusCode()) {
            404 => $notFoundMessage,
            409, 422 => $unprocessableMessage,
            default => 'Сервис корзины временно недоступен. Попробуйте снова позже.',
        };

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->keyboard->make(),
        );
    }
}
