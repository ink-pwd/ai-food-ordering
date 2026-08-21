<?php

namespace App\Telegram\Tracking;

use App\DTO\OrderingBackend\RestaurantData;
use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\Keyboards\MainMenuKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\Support\RestaurantNavigationContext;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final readonly class OrderTrackingContextResolver
{
    public function __construct(
        private TelegramSessionRecovery $sessionRecovery,
        private OrderingBackendClient $backend,
        private RestaurantNavigationContext $navigationContext,
        private MainMenuKeyboard $mainMenuKeyboard,
        private TelegramMessageEditor $messageEditor,
    ) {
    }

    /**
     * @return array{sessionToken: string, callbackContext: string}|null
     */
    public function resolveCallback(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): ?array {
        return $this->resolve(
            $bot,
            $restaurantId,
            $fingerprint,
            editMessage: true,
        );
    }

    /**
     * @return array{sessionToken: string, callbackContext: string}|null
     */
    public function resolveReply(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): ?array {
        return $this->resolve(
            $bot,
            $restaurantId,
            $fingerprint,
            editMessage: false,
        );
    }

    /**
     * @return array{sessionToken: string, callbackContext: string}|null
     */
    private function resolve(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
        bool $editMessage,
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

        if (! $this->containsRestaurant(
            $restaurants,
            $restaurantId,
        )) {
            $this->renderStale(
                $bot,
                $restaurantId,
                $sessionToken,
                $editMessage,
            );

            return null;
        }

        return [
            'sessionToken' => $sessionToken,
            'callbackContext' => $this->navigationContext->encode(
                $restaurantId,
                $sessionToken,
            ),
        ];
    }

    /** @param list<RestaurantData> $restaurants */
    private function containsRestaurant(
        array $restaurants,
        int $restaurantId,
    ): bool {
        foreach ($restaurants as $restaurant) {
            if ($restaurant->id === $restaurantId) {
                return true;
            }
        }

        return false;
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
