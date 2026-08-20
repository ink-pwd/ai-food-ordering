<?php

namespace App\Telegram\Cart;

use App\DTO\OrderingBackend\CartData;
use App\DTO\OrderingBackend\CartItemData;
use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\Keyboards\CartKeyboard;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final readonly class CartMutationFlow
{
    public function __construct(
        private OrderingBackendClient $backend,
        private CartPresenter $presenter,
        private CartKeyboard $keyboard,
        private TelegramMessageEditor $messageEditor,
    ) {
    }

    public function add(
        Nutgram $bot,
        int $productId,
        string $sessionToken,
        string $context,
    ): void {
        try {
            $cart = $this->backend->getOrCreateCurrentCart(
                $sessionToken,
            );
        } catch (OrderingBackendException $exception) {
            $this->presenter->failure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Кошик недоступний.',
                unprocessableMessage: 'Не вдалося підготувати кошик.',
                context: $context,
            );

            return;
        }

        $existingCartItem = $this->findCartItemByProductId(
            $cart->items,
            $productId,
        );

        try {
            $cart = $existingCartItem === null
                ? $this->backend->addCurrentCartItem(
                    sessionToken: $sessionToken,
                    productId: $productId,
                    quantity: 1,
                )
                : $this->backend->updateCurrentCartItem(
                    sessionToken: $sessionToken,
                    itemId: $existingCartItem->id,
                    quantity: $existingCartItem->quantity + 1,
                );
        } catch (OrderingBackendException $exception) {
            if (
                $existingCartItem === null
                && $exception->statusCode() === 409
            ) {
                $this->messageEditor->edit(
                    bot: $bot,
                    text: 'Цей товар уже є в кошику.',
                    keyboard: $this->keyboard->duplicateProduct(
                        $context,
                    ),
                );

                return;
            }

            $this->presenter->failure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Товар не знайдено.',
                unprocessableMessage: 'Не вдалося додати товар до кошика.',
                context: $context,
            );

            return;
        }

        $this->presenter->render(
            $bot,
            $cart,
            $context,
        );
    }

    public function changeQuantity(
        Nutgram $bot,
        int $itemId,
        int $difference,
        string $sessionToken,
        string $context,
    ): void {
        $cart = $this->currentCartOrReport(
            $bot,
            $sessionToken,
            $context,
        );

        if ($cart === null) {
            return;
        }

        $cartItem = $this->findCartItemById(
            $cart->items,
            $itemId,
        );

        if ($cartItem === null) {
            $this->presenter->renderMissingItem(
                $bot,
                $cart,
                $context,
            );

            return;
        }

        $quantity = $cartItem->quantity + $difference;

        if ($quantity < 1) {
            $this->removeCartItem(
                $bot,
                $sessionToken,
                $cartItem->id,
                $context,
            );

            return;
        }

        try {
            $updatedCart = $this->backend->updateCurrentCartItem(
                sessionToken: $sessionToken,
                itemId: $cartItem->id,
                quantity: $quantity,
            );
        } catch (OrderingBackendException $exception) {
            if ($this->renderAfterMissingItemMutation(
                $bot,
                $sessionToken,
                $exception,
                $context,
            )) {
                return;
            }

            $this->presenter->failure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Товар не знайдено в кошику.',
                unprocessableMessage: 'Не вдалося змінити кількість товару.',
                context: $context,
            );

            return;
        }

        $this->presenter->render(
            $bot,
            $updatedCart,
            $context,
        );
    }

    public function remove(
        Nutgram $bot,
        int $itemId,
        string $sessionToken,
        string $context,
    ): void {
        $cart = $this->currentCartOrReport(
            $bot,
            $sessionToken,
            $context,
        );

        if ($cart === null) {
            return;
        }

        $cartItem = $this->findCartItemById(
            $cart->items,
            $itemId,
        );

        if ($cartItem === null) {
            $this->presenter->renderMissingItem(
                $bot,
                $cart,
                $context,
            );

            return;
        }

        $this->removeCartItem(
            $bot,
            $sessionToken,
            $cartItem->id,
            $context,
        );
    }

    public function clear(
        Nutgram $bot,
        string $sessionToken,
        string $context,
    ): void {
        try {
            $cart = $this->backend->clearCurrentCart(
                $sessionToken,
            );
        } catch (OrderingBackendException $exception) {
            $this->presenter->failure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Кошик недоступний.',
                unprocessableMessage: 'Не вдалося очистити кошик.',
                context: $context,
            );

            return;
        }

        $this->presenter->render(
            $bot,
            $cart,
            $context,
        );
    }

    public function currentCartOrReport(
        Nutgram $bot,
        string $sessionToken,
        string $context,
    ): ?CartData {
        try {
            return $this->backend->currentCart(
                $sessionToken,
            );
        } catch (OrderingBackendException $exception) {
            $this->presenter->failure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Кошик недоступний.',
                unprocessableMessage: 'Не вдалося оновити кошик.',
                context: $context,
            );

            return null;
        }
    }

    private function removeCartItem(
        Nutgram $bot,
        string $sessionToken,
        int $itemId,
        string $context,
    ): void {
        try {
            $cart = $this->backend->removeCurrentCartItem(
                $itemId,
                $sessionToken,
            );
        } catch (OrderingBackendException $exception) {
            if ($this->renderAfterMissingItemMutation(
                $bot,
                $sessionToken,
                $exception,
                $context,
            )) {
                return;
            }

            $this->presenter->failure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Товар уже відсутній у кошику.',
                unprocessableMessage: 'Не вдалося видалити товар із кошика.',
                context: $context,
            );

            return;
        }

        $this->presenter->render(
            $bot,
            $cart,
            $context,
        );
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

        $cart = $this->currentCartOrReport(
            $bot,
            $sessionToken,
            $context,
        );

        if ($cart !== null) {
            $this->presenter->renderMissingItem(
                $bot,
                $cart,
                $context,
            );
        }

        return true;
    }

    /**
     * @param  list<CartItemData>  $items
     */
    private function findCartItemByProductId(
        array $items,
        int $productId,
    ): ?CartItemData {
        foreach ($items as $cartItem) {
            if ($cartItem->productId === $productId) {
                return $cartItem;
            }
        }

        return null;
    }

    /**
     * @param  list<CartItemData>  $items
     */
    private function findCartItemById(
        array $items,
        int $itemId,
    ): ?CartItemData {
        foreach ($items as $cartItem) {
            if ($cartItem->id === $itemId) {
                return $cartItem;
            }
        }

        return null;
    }
}
