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
use App\Telegram\Keyboards\PaymentKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\Support\RestaurantNavigationContext;
use App\Telegram\TelegramMessageEditor;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

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
        private readonly RestaurantNavigationContext $navigationContext,
        private readonly TelegramMessageEditor $messageEditor,
        private readonly PaymentKeyboard $paymentKeyboard,
    ) {}

    public function checkout(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->validatedContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        try {
            $cart = $this->backend->currentCart($context['sessionToken']);
        } catch (OrderingBackendException $exception) {
            $this->handleCartFailure($bot, $exception, $context['callbackContext']);

            return;
        }

        if ($cart['items'] === [] || $cart['status'] !== 'active') {
            $this->renderCart($bot, $cart, $context['callbackContext']);

            return;
        }

        try {
            $restaurants = $this->backend->currentSessionRestaurants(
                $context['sessionToken'],
            );
        } catch (OrderingBackendException $exception) {
            $this->handleCartFailure(
                $bot,
                $exception,
                $context['callbackContext'],
            );

            return;
        }

        $restaurant = collect($restaurants)->first(
            fn (array $restaurant): bool => $restaurant['id'] === $restaurantId,
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

        $paymentTypes = $restaurant['available_payment_types'];

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
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->validatedContext(
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
            if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
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

        if ($cart['items'] === [] || $cart['status'] !== 'active') {
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
            text: $this->checkoutFormatter->confirmation($cart),
            keyboard: $this->checkoutKeyboard->confirmation(
                $idempotencyKey,
                $context['callbackContext'],
            ),
        );
    }

    public function confirm(Nutgram $bot, string $idempotencyKey, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->validatedContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        if (! Str::isUuid($idempotencyKey)) {
            $this->messageEditor->edit(
                bot: $bot,
                text: 'Не вдалося підтвердити замовлення. Поверніться до кошика та повторіть оформлення.',
                keyboard: $this->orderKeyboard->backToCart($context['callbackContext']),
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
            $this->handleOrderCreationFailure($bot, $exception, $context['callbackContext']);

            return;
        }

        $this->renderOrderFlow($bot, $order, $context['sessionToken'], $context['callbackContext']);
    }

    public function cancel(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->validatedContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        try {
            $cart = $this->backend->currentCart($context['sessionToken']);
        } catch (OrderingBackendException $exception) {
            $this->handleCartFailure($bot, $exception, $context['callbackContext']);

            return;
        }

        $this->renderCart($bot, $cart, $context['callbackContext']);
    }

    public function refresh(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->validatedContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        try {
            $order = $this->backend->currentOrder($context['sessionToken']);
        } catch (OrderingBackendException $exception) {
            $this->handleCurrentOrderFailure($bot, $exception, $context['callbackContext']);

            return;
        }

        $this->renderOrderFlow($bot, $order, $context['sessionToken'], $context['callbackContext']);
    }

    public function refreshPayment(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->validatedContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        try {
            $payment = $this->backend->currentPayment($context['sessionToken']);
        } catch (OrderingBackendException $exception) {
            $this->handlePaymentFailure($bot, $exception, $context['callbackContext']);

            return;
        }

        $this->renderPayment($bot, $payment, $context['sessionToken'], $context['callbackContext']);
    }

    /**
     * @param  array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}  $cart
     */
    private function renderCart(Nutgram $bot, array $cart, string $context): void
    {
        $this->messageEditor->edit(
            bot: $bot,
            text: $this->cartFormatter->format($cart),
            keyboard: $this->cartKeyboard->make($cart['items'], $cart['status'], $context),
        );
    }

    /**
     * @param  array{id: int, external_order_id: ?string, status: string, failure_message: ?string, receiving_type: string, payment_type: int, fulfillment: array<string, mixed>, total: string, currency: string, payment: array{status: string, checkout_url: ?string, payment_received_at: ?string, qr_ready: bool}, items: list<array{product_id: ?int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}  $order
     */
    private function renderOrderFlow(
        Nutgram $bot,
        array $order,
        string $sessionToken,
        string $context,
    ): void {
        if ($order['status'] === 'creating' || $order['status'] === 'failed') {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->orderFormatter->format($order),
                keyboard: $this->orderKeyboard->order(
                    $order['status'],
                    $context,
                ),
            );

            return;
        }

        // Cash and terminal payments do not have an online payment flow.
        if ($order['payment_type'] !== 2) {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->orderFormatter->format($order),
                keyboard: $this->orderKeyboard->order(
                    $order['status'],
                    $context,
                ),
            );

            return;
        }

        $payment = $order['payment'] + ['http_status' => 200];

        $this->renderPayment(
            $bot,
            $payment,
            $sessionToken,
            $context,
            $this->orderFormatter->format($order),
        );
    }

    /**
     * @param  array{status: string, checkout_url: ?string, payment_received_at: ?string, http_status?: int, qr_ready?: bool}  $payment
     */
    private function renderPayment(Nutgram $bot, array $payment, string $sessionToken, string $context, ?string $orderText = null): void
    {
        if (($payment['payment_received_at'] ?? null) !== null) {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->combineText($orderText, $this->orderFormatter->payment($payment)),
                keyboard: $this->orderKeyboard->paymentReady('', $context, includePayButton: false),
            );

            return;
        }

        if ($payment['status'] !== 'ready') {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->combineText($orderText, $this->orderFormatter->payment($payment)),
                keyboard: $this->orderKeyboard->paymentPending($context),
            );

            return;
        }

        $checkoutUrl = $payment['checkout_url'];

        if (! $this->isTrustedCheckoutUrl($checkoutUrl)) {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->combineText($orderText, '⚠️ Платіжні дані тимчасово недоступні. Оновіть оплату трохи пізніше.'),
                keyboard: $this->orderKeyboard->paymentPending($context),
            );

            return;
        }

        $paymentText = $this->combineText(
            $orderText,
            $this->orderFormatter->payment($payment),
        );

        if ($this->sendPaymentQr(
            $bot,
            $sessionToken,
            $paymentText,
            $checkoutUrl,
            $context,
        )) {
            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $this->combineText(
                $orderText,
                $this->orderFormatter->payment(
                    $payment,
                    '⚠️ QR-код тимчасово недоступний. Скористайтеся кнопкою оплати.',
                ),
            ),
            keyboard: $this->orderKeyboard->paymentReady($checkoutUrl, $context),
        );
    }

    private function sendPaymentQr(
        Nutgram $bot,
        string  $sessionToken,
        string  $caption,
        string  $checkoutUrl,
        string  $context,
    ): bool
    {
        try {
            $qr = $this->backend->currentPaymentQr($sessionToken);
        } catch (OrderingBackendException $exception) {
            \Illuminate\Support\Facades\Log::error('QR DEBUG: backend exception', [
                'message' => $exception->getMessage(),
                'status' => $exception->statusCode(),
            ]);

            return false;
        }

        \Illuminate\Support\Facades\Log::info('QR DEBUG: backend response', [
            'status' => $qr['status'] ?? null,
            'has_contents' => isset($qr['contents']),
            'contents_size' => isset($qr['contents'])
                ? strlen($qr['contents'])
                : null,
        ]);

        if (($qr['status'] ?? null) !== 'ready') {
            \Illuminate\Support\Facades\Log::warning('QR DEBUG: QR is not ready');

            return false;
        }

        $stream = fopen('php://temp', 'rb+');

        if ($stream === false) {
            \Illuminate\Support\Facades\Log::error('QR DEBUG: failed to open temp stream');

            return false;
        }

        try {
            fwrite($stream, $qr['contents']);
            rewind($stream);

            \Illuminate\Support\Facades\Log::info('QR DEBUG: sending photo to Telegram', [
                'contents_size' => strlen($qr['contents']),
            ]);

            $bot->sendPhoto(
                photo: InputFile::make($stream, 'payment-qr.png'),
                caption: $caption,
                reply_markup: $this->orderKeyboard->paymentReady(
                    $checkoutUrl,
                    $context,
                ),
            );

            \Illuminate\Support\Facades\Log::info('QR DEBUG: photo sent successfully');
        } finally {
            fclose($stream);
        }

        return true;
    }

    private function combineText(?string $orderText, string $paymentText): string
    {
        if ($orderText === null) {
            return $paymentText;
        }

        return $orderText."\n\n".$paymentText;
    }

    private function isTrustedCheckoutUrl(?string $checkoutUrl): bool
    {
        return is_string($checkoutUrl)
            && filter_var($checkoutUrl, FILTER_VALIDATE_URL) !== false
            && str_starts_with($checkoutUrl, 'https://');
    }

    private function handleCartFailure(Nutgram $bot, OrderingBackendException $exception, string $context): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        $message = match ($exception->statusCode()) {
            404 => 'Кошик недоступний.',
            409, 422 => 'Не вдалося підготувати оформлення замовлення.',
            default => 'Сервіс кошика тимчасово недоступний. Спробуйте пізніше.',
        };

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->cartKeyboard->make(context: $context),
        );
    }

    private function handleOrderCreationFailure(Nutgram $bot, OrderingBackendException $exception, string $context): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        if ($exception->statusCode() === 422) {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->unprocessableOrderMessage($exception),
                keyboard: $this->orderKeyboard->backToCart($context),
            );

            return;
        }

        if ($exception->statusCode() === 404) {
            $this->messageEditor->edit(
                bot: $bot,
                text: 'Не вдалося знайти активний кошик для оформлення замовлення.',
                keyboard: $this->orderKeyboard->backToCart($context),
            );

            return;
        }

        $message = $exception->statusCode() === 409
            ? 'Замовлення вже оформлюється або кошик змінився. Перевірте поточне замовлення.'
            : 'Не вдалося однозначно визначити результат оформлення. Перевірте статус замовлення перед повторною спробою.';

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->orderKeyboard->statusCheck($context),
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
            return 'Зараз ресторан не приймає замовлення. Спробуйте оформити замовлення в робочий час.';
        }

        return 'Не вдалося оформити замовлення. Перевірте кошик і контактні дані.';
    }

    private function handleCurrentOrderFailure(Nutgram $bot, OrderingBackendException $exception, string $context): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        $message = $exception->statusCode() === 404
            ? 'Поточне замовлення не знайдено.'
            : 'Не вдалося перевірити статус замовлення. Спробуйте пізніше.';

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->orderKeyboard->statusCheck($context),
        );
    }

    private function handlePaymentFailure(Nutgram $bot, OrderingBackendException $exception, string $context): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: 'Не вдалося оновити оплату. Спробуйте пізніше.',
            keyboard: $this->orderKeyboard->paymentPending($context),
        );
    }

    /**
     * @return array{sessionToken: string, callbackContext: string}|null
     */
    private function validatedContext(Nutgram $bot, int $restaurantId, string $fingerprint): ?array
    {
        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return null;
        }

        if (! $this->navigationContext->isValid($restaurantId, $fingerprint, $sessionToken)) {
            $this->messageEditor->edit(
                bot: $bot,
                text: RestaurantNavigationContext::STALE_MESSAGE,
                keyboard: $this->cartKeyboard->make(context: $this->navigationContext->encode($restaurantId, $sessionToken)),
            );

            return null;
        }

        try {
            $restaurants = $this->backend->currentSessionRestaurants($sessionToken);
        } catch (OrderingBackendException $exception) {
            if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
                return null;
            }

            $this->messageEditor->edit(
                bot: $bot,
                text: RestaurantNavigationContext::STALE_MESSAGE,
                keyboard: $this->cartKeyboard->make(context: $this->navigationContext->encode($restaurantId, $sessionToken)),
            );

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

        $this->messageEditor->edit(
            bot: $bot,
            text: RestaurantNavigationContext::STALE_MESSAGE,
            keyboard: $this->cartKeyboard->make(context: $this->navigationContext->encode($restaurantId, $sessionToken)),
        );

        return null;
    }
}
