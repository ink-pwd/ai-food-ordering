<?php

namespace App\Telegram\Handlers;

use App\Telegram\CallbackAcknowledger;
use App\Telegram\Fulfillment\DeliveryAddressFlow;
use App\Telegram\Fulfillment\FulfillmentContextResolver;
use App\Telegram\Fulfillment\FulfillmentPresenter;
use SergiX44\Nutgram\Nutgram;

final readonly class FulfillmentHandler
{
    public function __construct(
        private CallbackAcknowledger $callbackAcknowledger,
        private FulfillmentContextResolver $contextResolver,
        private FulfillmentPresenter $presenter,
        private DeliveryAddressFlow $deliveryAddressFlow,
        private AiAssistantHandler $aiAssistant,
    ) {
    }

    public function menu(
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

        $this->presenter->renderOptions(
            $bot,
            $context['sessionToken'],
            $context['callbackContext'],
        );
    }

    public function delivery(
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

        if (! $this->presenter->select(
            $bot,
            $context['sessionToken'],
            'delivery',
            $context['callbackContext'],
        )) {
            return;
        }

        $this->deliveryAddressFlow->askType(
            $bot,
            $context['callbackContext'],
        );
    }

    public function pickup(
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

        if (! $this->presenter->select(
            $bot,
            $context['sessionToken'],
            'pickup',
            $context['callbackContext'],
        )) {
            return;
        }

        $this->presenter->renderPickupAddresses(
            $bot,
            $context['sessionToken'],
            $context['callbackContext'],
        );
    }

    public function retryDeliveryAddress(
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

        $this->deliveryAddressFlow->askType(
            $bot,
            $context['callbackContext'],
        );
    }

    public function deliveryAddressType(
        Nutgram $bot,
        int $type,
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

        $this->deliveryAddressFlow->startForType(
            bot: $bot,
            type: $type,
            restaurantId: $restaurantId,
            sessionToken: $context['sessionToken'],
            callbackContext: $context['callbackContext'],
        );
    }

    public function address(
        Nutgram $bot,
        string $address,
    ): void {
        if ($this->aiAssistant->handleInputIfExpected($bot, $address)) {
            return;
        }

        $this->deliveryAddressFlow->handleAddress(
            $bot,
            $address,
        );
    }

    public function selectPickupAddress(
        Nutgram $bot,
        int $restaurantAddressId,
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

        $this->presenter->selectPickupAddress(
            $bot,
            $context['sessionToken'],
            $restaurantAddressId,
            $context['callbackContext'],
        );
    }

    /**
     * @return array{
     *     sessionToken: string,
     *     callbackContext: string
     * }|null
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
