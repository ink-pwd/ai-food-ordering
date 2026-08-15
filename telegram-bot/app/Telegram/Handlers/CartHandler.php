<?php

namespace App\Telegram\Handlers;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\CallbackAcknowledger;
use App\Telegram\Formatting\CartMessageFormatter;
use App\Telegram\Keyboards\CartKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\Support\RestaurantNavigationContext;
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
        private readonly RestaurantNavigationContext $navigationContext,
        private readonly TelegramMessageEditor $messageEditor,
    ) {}

    public function show(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->restaurantContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        try {
            $cart = $this->backend->getOrCreateCurrentCart($context['sessionToken']);
        } catch (OrderingBackendException $exception) {
            $this->handleBackendFailure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Кошик недоступний.',
                unprocessableMessage: 'Не вдалося відкрити кошик.',
                context: $context['callbackContext'],
            );

            return;
        }

        $this->renderCart($bot, $cart, $context['callbackContext']);
    }

    public function add(Nutgram $bot, int $productId, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->restaurantContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        try {
            $cart = $this->backend->getOrCreateCurrentCart($context['sessionToken']);
        } catch (OrderingBackendException $exception) {
            $this->handleBackendFailure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Кошик недоступний.',
                unprocessableMessage: 'Не вдалося підготувати кошик.',
                context: $context['callbackContext'],
            );

            return;
        }

        $existingCartItem = $this->findCartItemByProductId($cart['items'], $productId);

        try {
            $cart = $existingCartItem === null
                ? $this->backend->addCurrentCartItem(
                    sessionToken: $context['sessionToken'],
                    productId: $productId,
                    quantity: 1,
                )
                : $this->backend->updateCurrentCartItem(
                    sessionToken: $context['sessionToken'],
                    itemId: $existingCartItem['id'],
                    quantity: $existingCartItem['quantity'] + 1,
                );
        } catch (OrderingBackendException $exception) {
            if ($existingCartItem === null && $exception->statusCode() === 409) {
                $this->messageEditor->edit(
                    bot: $bot,
                    text: 'Цей товар уже є в кошику.',
                    keyboard: $this->keyboard->duplicateProduct($context['callbackContext']),
                );

                return;
            }

            $this->handleBackendFailure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Товар не знайдено.',
                unprocessableMessage: 'Не вдалося додати товар до кошика.',
                context: $context['callbackContext'],
            );

            return;
        }

        $this->renderCart($bot, $cart, $context['callbackContext']);
    }

    public function increment(Nutgram $bot, int $itemId, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $this->changeQuantity($bot, $itemId, 1, $restaurantId, $fingerprint);
    }

    public function decrement(Nutgram $bot, int $itemId, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $this->changeQuantity($bot, $itemId, -1, $restaurantId, $fingerprint);
    }

    public function remove(Nutgram $bot, int $itemId, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->restaurantContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        $cart = $this->currentCartOrReport($bot, $context['sessionToken'], $context['callbackContext']);

        if ($cart === null) {
            return;
        }

        $cartItem = $this->findCartItemById($cart['items'], $itemId);

        if ($cartItem === null) {
            $this->renderCartWithMissingItemNotice($bot, $cart, $context['callbackContext']);

            return;
        }

        $this->removeCartItem($bot, $context['sessionToken'], $cartItem['id'], $context['callbackContext']);
    }

    public function clear(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->restaurantContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: 'Очистити весь кошик?',
            keyboard: $this->keyboard->clearConfirmation($context['callbackContext']),
        );
    }

    public function confirmClear(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->restaurantContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        try {
            $cart = $this->backend->clearCurrentCart($context['sessionToken']);
        } catch (OrderingBackendException $exception) {
            $this->handleBackendFailure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Кошик недоступний.',
                unprocessableMessage: 'Не вдалося очистити кошик.',
                context: $context['callbackContext'],
            );

            return;
        }

        $this->renderCart($bot, $cart, $context['callbackContext']);
    }

    public function cancelClear(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->restaurantContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        $cart = $this->currentCartOrReport($bot, $context['sessionToken'], $context['callbackContext']);

        if ($cart === null) {
            return;
        }

        $this->renderCart($bot, $cart, $context['callbackContext']);
    }

    public function noop(Nutgram $bot, int $itemId, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $this->restaurantContext($bot, $restaurantId, $fingerprint);
    }

    /**
     * @param  array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}  $cart
     */
    private function renderCart(Nutgram $bot, array $cart, string $context): void
    {
        $this->messageEditor->edit(
            bot: $bot,
            text: $this->formatter->format($cart),
            keyboard: $this->keyboard->make($cart['items'], $cart['status'], $context),
        );
    }

    private function changeQuantity(Nutgram $bot, int $itemId, int $difference, int $restaurantId, string $fingerprint): void
    {
        $context = $this->restaurantContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        $cart = $this->currentCartOrReport($bot, $context['sessionToken'], $context['callbackContext']);

        if ($cart === null) {
            return;
        }

        $cartItem = $this->findCartItemById($cart['items'], $itemId);

        if ($cartItem === null) {
            $this->renderCartWithMissingItemNotice($bot, $cart, $context['callbackContext']);

            return;
        }

        $quantity = $cartItem['quantity'] + $difference;

        if ($quantity < 1) {
            $this->removeCartItem($bot, $context['sessionToken'], $cartItem['id'], $context['callbackContext']);

            return;
        }

        try {
            $updatedCart = $this->backend->updateCurrentCartItem(
                sessionToken: $context['sessionToken'],
                itemId: $cartItem['id'],
                quantity: $quantity,
            );
        } catch (OrderingBackendException $exception) {
            if ($this->renderAfterMissingItemMutation($bot, $context['sessionToken'], $exception, $context['callbackContext'])) {
                return;
            }

            $this->handleBackendFailure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Товар не знайдено в кошику.',
                unprocessableMessage: 'Не вдалося змінити кількість товару.',
                context: $context['callbackContext'],
            );

            return;
        }

        $this->renderCart($bot, $updatedCart, $context['callbackContext']);
    }

    /**
     * @return array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}|null
     */
    private function currentCartOrReport(Nutgram $bot, string $sessionToken, string $context): ?array
    {
        try {
            return $this->backend->currentCart($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleBackendFailure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Кошик недоступний.',
                unprocessableMessage: 'Не вдалося оновити кошик.',
                context: $context,
            );

            return null;
        }
    }

    private function removeCartItem(Nutgram $bot, string $sessionToken, int $itemId, string $context): void
    {
        try {
            $cart = $this->backend->removeCurrentCartItem($itemId, $sessionToken);
        } catch (OrderingBackendException $exception) {
            if ($this->renderAfterMissingItemMutation($bot, $sessionToken, $exception, $context)) {
                return;
            }

            $this->handleBackendFailure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Товар уже відсутній у кошику.',
                unprocessableMessage: 'Не вдалося видалити товар із кошика.',
                context: $context,
            );

            return;
        }

        $this->renderCart($bot, $cart, $context);
    }

    private function renderAfterMissingItemMutation(
        Nutgram $bot,
        string $sessionToken,
        OrderingBackendException $exception,
        string $context,
    ): bool {
        if ($exception->statusCode() !== 404) {
            return false;
        }

        $cart = $this->currentCartOrReport($bot, $sessionToken, $context);

        if ($cart !== null) {
            $this->renderCartWithMissingItemNotice($bot, $cart, $context);
        }

        return true;
    }

    /**
     * @param  array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}  $cart
     */
    private function renderCartWithMissingItemNotice(Nutgram $bot, array $cart, string $context): void
    {
        $this->messageEditor->edit(
            bot: $bot,
            text: $this->formatter->formatWithNotice($cart, 'Товар уже відсутній у кошику.'),
            keyboard: $this->keyboard->make($cart['items'], $cart['status'], $context),
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
        string $context,
    ): void {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        $message = match ($exception->statusCode()) {
            404 => $notFoundMessage,
            409, 422 => $unprocessableMessage,
            default => 'Сервіс кошика тимчасово недоступний. Спробуйте пізніше.',
        };

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->keyboard->make(context: $context),
        );
    }

    /**
     * @return array{sessionToken: string, callbackContext: string}|null
     */
    private function restaurantContext(Nutgram $bot, int $restaurantId, string $fingerprint): ?array
    {
        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return null;
        }

        if (! $this->navigationContext->isValid($restaurantId, $fingerprint, $sessionToken)) {
            $this->stale($bot, $restaurantId, $sessionToken);

            return null;
        }

        try {
            $restaurants = $this->backend->currentSessionRestaurants($sessionToken);
        } catch (OrderingBackendException $exception) {
            if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
                return null;
            }

            $this->stale($bot, $restaurantId, $sessionToken);

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

        $this->stale($bot, $restaurantId, $sessionToken);

        return null;
    }

    private function stale(Nutgram $bot, int $restaurantId, string $sessionToken): void
    {
        $this->messageEditor->edit(
            bot: $bot,
            text: RestaurantNavigationContext::STALE_MESSAGE,
            keyboard: $this->keyboard->make(context: $this->navigationContext->encode($restaurantId, $sessionToken)),
        );
    }
}
