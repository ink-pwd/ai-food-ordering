<?php

namespace App\Telegram\Ai;

use App\DTO\Ai\AiContextData;
use App\DTO\OrderingBackend\RestaurantData;
use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\Keyboards\MainMenuKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\Support\RestaurantNavigationContext;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final readonly class AiContextResolver
{
    public function __construct(
        private TelegramSessionRecovery $sessionRecovery,
        private OrderingBackendClient $backend,
        private RestaurantNavigationContext $navigationContext,
        private MainMenuKeyboard $mainMenuKeyboard,
        private TelegramMessageEditor $messageEditor,
    ) {
    }

    public function resolveCallback(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): ?AiContextData {
        return $this->resolve(
            $bot,
            $restaurantId,
            $fingerprint,
            editMessage: true,
        );
    }

    public function resolveReply(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): ?AiContextData {
        return $this->resolve(
            $bot,
            $restaurantId,
            $fingerprint,
            editMessage: false,
        );
    }

    private function resolve(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
        bool $editMessage,
    ): ?AiContextData {
        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return null;
        }

        if (! $this->navigationContext->isValid(
            $restaurantId,
            $fingerprint,
            $sessionToken,
        )) {
            $this->renderStale(
                $bot,
                $restaurantId,
                $sessionToken,
                $editMessage,
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

            $this->renderStale(
                $bot,
                $restaurantId,
                $sessionToken,
                $editMessage,
            );

            return null;
        }

        $restaurant = $this->findRestaurant(
            $restaurants,
            $restaurantId,
        );

        if ($restaurant === null) {
            $this->renderStale(
                $bot,
                $restaurantId,
                $sessionToken,
                $editMessage,
            );

            return null;
        }

        return new AiContextData(
            sessionToken: $sessionToken,
            callbackContext: $this->navigationContext->encode(
                $restaurantId,
                $sessionToken,
            ),
            restaurantId: $restaurantId,
            restaurantSlug: $restaurant->slug,
        );
    }

    /**
     * @param  list<RestaurantData>  $restaurants
     */
    private function findRestaurant(
        array $restaurants,
        int $restaurantId,
    ): ?RestaurantData {
        foreach ($restaurants as $restaurant) {
            if ($restaurant->id === $restaurantId) {
                return $restaurant;
            }
        }

        return null;
    }

    private function renderStale(
        Nutgram $bot,
        int $restaurantId,
        string $sessionToken,
        bool $editMessage,
    ): void {
        $keyboard = $this->mainMenuKeyboard->make(
            $this->navigationContext->encode(
                $restaurantId,
                $sessionToken,
            ),
        );

        if ($editMessage) {
            $this->messageEditor->edit(
                bot: $bot,
                text: RestaurantNavigationContext::STALE_MESSAGE,
                keyboard: $keyboard,
            );

            return;
        }

        $bot->sendMessage(
            text: RestaurantNavigationContext::STALE_MESSAGE,
            reply_markup: $keyboard,
        );
    }
}
