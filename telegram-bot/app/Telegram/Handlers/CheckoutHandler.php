<?php

namespace App\Telegram\Handlers;

use App\DTO\OrderingBackend\CartData;
use App\DTO\OrderingBackend\RestaurantData;
use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\CallbackAcknowledger;
use App\Telegram\Checkout\CheckoutContextResolver;
use App\Telegram\Checkout\CheckoutFailurePresenter;
use App\Telegram\Checkout\CheckoutOrderPresenter;
use App\Telegram\Formatting\CartMessageFormatter;
use App\Telegram\Formatting\CheckoutMessageFormatter;
use App\Telegram\Keyboards\CartKeyboard;
use App\Telegram\Keyboards\CheckoutKeyboard;
use App\Telegram\Keyboards\OrderKeyboard;
use App\Telegram\Keyboards\PaymentKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\TelegramMessageEditor;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Nutgram;

final readonly class CheckoutHandler
{
    public function __construct(
        private CallbackAcknowledger $callbackAcknowledger,
        private TelegramSessionRecovery $sessionRecovery,
        private OrderingBackendClient $backend,
        private CheckoutMessageFormatter $checkoutFormatter,
        private CheckoutKeyboard $checkoutKeyboard,
        private CartMessageFormatter $cartFormatter,
        private CartKeyboard $cartKeyboard,
        private OrderKeyboard $orderKeyboard,
        private TelegramMessageEditor $messageEditor,
        private PaymentKeyboard $paymentKeyboard,
        private CheckoutContextResolver $contextResolver,
        private CheckoutOrderPresenter $orderPresenter,
        private CheckoutFailurePresenter $failurePresenter,
    ) {
    }

    public function checkout(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $context = $this->resolveContext(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        $cart = $this->currentCartOrPresentFailure(
            $bot,
            $context['sessionToken'],
            $context['callbackContext'],
        );

        if ($cart === null) {
            return;
        }

        if (
            $cart->items === []
            || $cart->status !== 'active'
        ) {
            $this->renderCart(
                $bot,
                $cart,
                $context['callbackContext'],
            );

            return;
        }

        try {
            $restaurants =
                $this->backend->currentSessionRestaurants(
                    $context['sessionToken'],
                );
        } catch (OrderingBackendException $exception) {
            $this->failurePresenter->cart(
                $bot,
                $exception,
                $context['callbackContext'],
            );

            return;
        }

        $restaurant = collect($restaurants)->first(
            fn (RestaurantData $restaurant): bool => $restaurant->id === $restaurantId,
        );

        if ($restaurant === null) {
            $this->messageEditor->edit(
                bot: $bot,
                text: '⚠️ Не вдалося отримати дані ресторану.',
                keyboard: $this->orderKeyboard->backToCart(
                    $context['callbackContext'],
                ),
            );

            return;
        }

        $paymentTypes =
            $restaurant->availablePaymentTypes;

        if ($paymentTypes === []) {
            $this->messageEditor->edit(
                bot: $bot,
                text: '⚠️ Для цього ресторану зараз немає доступних способів оплати.',
                keyboard: $this->orderKeyboard->backToCart(
                    $context['callbackContext'],
                ),
            );

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: '💳 Оберіть спосіб оплати:',
            keyboard: $this->paymentKeyboard->make(
                $paymentTypes,
                $context['callbackContext'],
            ),
        );
    }

    public function payment(
        Nutgram $bot,
        int $paymentType,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $context = $this->resolveContext(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        if (! in_array($paymentType, [1, 2, 3], true)) {
            $this->messageEditor->edit(
                bot: $bot,
                text: '⚠️ Невідомий спосіб оплати.',
                keyboard: $this->orderKeyboard->backToCart(
                    $context['callbackContext'],
                ),
            );

            return;
        }

        try {
            $this->backend->updateCurrentSessionPayment(
                sessionToken: $context['sessionToken'],
                paymentType: $paymentType,
            );

            $cart = $this->backend->currentCart(
                $context['sessionToken'],
            );
        } catch (OrderingBackendException $exception) {
            if (
                $this->sessionRecovery
                    ->recoverIfUnauthorized(
                        $bot,
                        $exception,
                    )
            ) {
                return;
            }

            $this->messageEditor->edit(
                bot: $bot,
                text: $exception->statusCode() === 409
                    ? '⚠️ Цей спосіб оплати недоступний для ресторану.'
                    : '⚠️ Не вдалося зберегти спосіб оплати. Спробуйте ще раз.',
                keyboard: $this->orderKeyboard->backToCart(
                    $context['callbackContext'],
                ),
            );

            return;
        }

        if (
            $cart->items === []
            || $cart->status !== 'active'
        ) {
            $this->renderCart(
                $bot,
                $cart,
                $context['callbackContext'],
            );

            return;
        }

        $idempotencyKey = (string) Str::uuid();

        $this->messageEditor->edit(
            bot: $bot,
            text: $this->checkoutFormatter->confirmation(
                $cart,
            ),
            keyboard: $this->checkoutKeyboard->confirmation(
                $idempotencyKey,
                $context['callbackContext'],
            ),
        );
    }

    public function confirm(
        Nutgram $bot,
        string $idempotencyKey,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $context = $this->resolveContext(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        if (! Str::isUuid($idempotencyKey)) {
            $this->messageEditor->edit(
                bot: $bot,
                text: 'Не вдалося підтвердити замовлення. Поверніться до кошика та повторіть оформлення.',
                keyboard: $this->orderKeyboard->backToCart(
                    $context['callbackContext'],
                ),
            );

            return;
        }

        try {
            $order = $this->backend->createOrder(
                sessionToken: $context['sessionToken'],
                idempotencyKey: $idempotencyKey,
                deliveryTime: 0,
            );
        } catch (OrderingBackendException $exception) {
            $this->failurePresenter->orderCreation(
                $bot,
                $exception,
                $context['callbackContext'],
            );

            return;
        }

        $this->orderPresenter->orderFlow(
            $bot,
            $order,
            $context['sessionToken'],
            $context['callbackContext'],
        );
    }

    public function cancel(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $context = $this->resolveContext(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        $cart = $this->currentCartOrPresentFailure(
            $bot,
            $context['sessionToken'],
            $context['callbackContext'],
        );

        if ($cart === null) {
            return;
        }

        $this->renderCart(
            $bot,
            $cart,
            $context['callbackContext'],
        );
    }

    public function refresh(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $context = $this->resolveContext(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        try {
            $order = $this->backend->currentOrder(
                $context['sessionToken'],
            );
        } catch (OrderingBackendException $exception) {
            $this->failurePresenter->currentOrder(
                $bot,
                $exception,
                $context['callbackContext'],
            );

            return;
        }

        $this->orderPresenter->orderFlow(
            $bot,
            $order,
            $context['sessionToken'],
            $context['callbackContext'],
        );
    }

    public function refreshPayment(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): void {
        $context = $this->resolveContext(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        try {
            $payment = $this->backend->currentPayment(
                $context['sessionToken'],
            );
        } catch (OrderingBackendException $exception) {
            $this->failurePresenter->payment(
                $bot,
                $exception,
                $context['callbackContext'],
            );

            return;
        }

        $this->orderPresenter->payment(
            $bot,
            $payment,
            $context['sessionToken'],
            $context['callbackContext'],
        );
    }

    /**
     * @return array{
     *     sessionToken: string,
     *     callbackContext: string
     * }|null
     */
    private function resolveContext(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): ?array {
        if (
            ! $this->callbackAcknowledger
                ->acknowledge($bot)
        ) {
            return null;
        }

        return $this->contextResolver->resolve(
            $bot,
            $restaurantId,
            $fingerprint,
        );
    }

    private function currentCartOrPresentFailure(
        Nutgram $bot,
        string $sessionToken,
        string $callbackContext,
    ): ?CartData {
        try {
            return $this->backend->currentCart(
                $sessionToken,
            );
        } catch (OrderingBackendException $exception) {
            $this->failurePresenter->cart(
                $bot,
                $exception,
                $callbackContext,
            );

            return null;
        }
    }

    private function renderCart(
        Nutgram $bot,
        CartData $cart,
        string $context,
    ): void {
        $this->messageEditor->edit(
            bot: $bot,
            text: $this->cartFormatter->format($cart),
            keyboard: $this->cartKeyboard->make(
                $cart->items,
                $cart->status,
                $context,
            ),
        );
    }
}
