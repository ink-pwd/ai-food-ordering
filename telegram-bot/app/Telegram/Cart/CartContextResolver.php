<?php

namespace App\Telegram\Cart;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\Keyboards\CartKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\Support\RestaurantNavigationContext;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final readonly class CartContextResolver
{
    public function __construct(
        private TelegramSessionRecovery $sessionRecovery,
        private OrderingBackendClient $backend,
        private RestaurantNavigationContext $navigationContext,
        private CartKeyboard $keyboard,
        private TelegramMessageEditor $messageEditor,
    ) {
    }

    /**
     * @return array{sessionToken: string, callbackContext: string}|null
     */
    public function resolve(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): ?array {
        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return null;
        }

        if (! $this->navigationContext->isValid(
            $restaurantId,
            $fingerprint,
            $sessionToken,
        )) {
            $this->stale(
                $bot,
                $restaurantId,
                $sessionToken,
            );

            return null;
        }

        try {
            $restaurants = $this->backend->currentSessionRestaurants(
                $sessionToken,
            );
        } catch (OrderingBackendException $exception) {
            if ($this->sessionRecovery->recoverIfUnauthorized(
                $bot,
                $exception,
            )) {
                return null;
            }

            $this->stale(
                $bot,
                $restaurantId,
                $sessionToken,
            );

            return null;
        }

        foreach ($restaurants as $restaurant) {
            if ($restaurant->id === $restaurantId) {
                return [
                    'sessionToken' => $sessionToken,
                    'callbackContext' => $this->navigationContext->encode(
                        $restaurantId,
                        $sessionToken,
                    ),
                ];
            }
        }

        $this->stale(
            $bot,
            $restaurantId,
            $sessionToken,
        );

        return null;
    }

    private function stale(
        Nutgram $bot,
        int $restaurantId,
        string $sessionToken,
    ): void {
        $this->messageEditor->edit(
            bot: $bot,
            text: RestaurantNavigationContext::STALE_MESSAGE,
            keyboard: $this->keyboard->make(
                context: $this->navigationContext->encode(
                    $restaurantId,
                    $sessionToken,
                ),
            ),
        );
    }
}
