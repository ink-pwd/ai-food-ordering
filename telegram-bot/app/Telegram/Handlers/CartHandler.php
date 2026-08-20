<?php

namespace App\Telegram\Handlers;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\CallbackAcknowledger;
use App\Telegram\Cart\CartContextResolver;
use App\Telegram\Cart\CartMutationFlow;
use App\Telegram\Cart\CartPresenter;
use App\Telegram\Keyboards\CartKeyboard;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final readonly class CartHandler
{
    public function __construct(
        private CallbackAcknowledger $callbackAcknowledger,
        private OrderingBackendClient $backend,
        private CartKeyboard $keyboard,
        private TelegramMessageEditor $messageEditor,
        private CartContextResolver $contextResolver,
        private CartMutationFlow $mutationFlow,
        private CartPresenter $presenter,
    ) {
    }

    public function show(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $context = $this->resolveCallbackContext(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        try {
            $cart = $this->backend->getOrCreateCurrentCart(
                $context['sessionToken'],
            );
        } catch (OrderingBackendException $exception) {
            $this->presenter->failure(
                bot: $bot,
                exception: $exception,
                notFoundMessage: 'Кошик недоступний.',
                unprocessableMessage: 'Не вдалося відкрити кошик.',
                context: $context['callbackContext'],
            );

            return;
        }

        $this->presenter->render(
            $bot,
            $cart,
            $context['callbackContext'],
        );
    }

    public function add(
        Nutgram $bot,
        int $productId,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $context = $this->resolveCallbackContext(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        $this->mutationFlow->add(
            $bot,
            $productId,
            $context['sessionToken'],
            $context['callbackContext'],
        );
    }

    public function increment(
        Nutgram $bot,
        int $itemId,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $this->changeQuantity(
            $bot,
            $itemId,
            1,
            $restaurantId,
            $fingerprint,
        );
    }

    public function decrement(
        Nutgram $bot,
        int $itemId,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $this->changeQuantity(
            $bot,
            $itemId,
            -1,
            $restaurantId,
            $fingerprint,
        );
    }

    public function remove(
        Nutgram $bot,
        int $itemId,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $context = $this->resolveCallbackContext(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        $this->mutationFlow->remove(
            $bot,
            $itemId,
            $context['sessionToken'],
            $context['callbackContext'],
        );
    }

    public function clear(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $context = $this->resolveCallbackContext(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: 'Очистити весь кошик?',
            keyboard: $this->keyboard->clearConfirmation(
                $context['callbackContext'],
            ),
        );
    }

    public function confirmClear(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $context = $this->resolveCallbackContext(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        $this->mutationFlow->clear(
            $bot,
            $context['sessionToken'],
            $context['callbackContext'],
        );
    }

    public function cancelClear(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $context = $this->resolveCallbackContext(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        $cart = $this->mutationFlow->currentCartOrReport(
            $bot,
            $context['sessionToken'],
            $context['callbackContext'],
        );

        if ($cart === null) {
            return;
        }

        $this->presenter->render(
            $bot,
            $cart,
            $context['callbackContext'],
        );
    }

    public function noop(
        Nutgram $bot,
        int $itemId,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $this->resolveCallbackContext(
            $bot,
            $restaurantId,
            $fingerprint,
        );
    }

    private function changeQuantity(
        Nutgram $bot,
        int $itemId,
        int $difference,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $context = $this->resolveCallbackContext(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        $this->mutationFlow->changeQuantity(
            $bot,
            $itemId,
            $difference,
            $context['sessionToken'],
            $context['callbackContext'],
        );
    }

    /**
     * @return array{sessionToken: string, callbackContext: string}|null
     */
    private function resolveCallbackContext(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): ?array {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return null;
        }

        return $this->contextResolver->resolve(
            $bot,
            $restaurantId,
            $fingerprint,
        );
    }
}
