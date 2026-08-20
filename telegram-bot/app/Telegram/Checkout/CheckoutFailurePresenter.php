<?php

namespace App\Telegram\Checkout;

use App\Exceptions\OrderingBackendException;
use App\Telegram\Keyboards\CartKeyboard;
use App\Telegram\Keyboards\OrderKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\TelegramMessageEditor;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Nutgram;

final readonly class CheckoutFailurePresenter
{
    private const RESTAURANT_HOURS_REJECTIONS = [
        'Компанiя не працює у вказаний в замовленні час',
        'Компания не работает в это время',
    ];

    public function __construct(
        private TelegramSessionRecovery $sessionRecovery,
        private TelegramMessageEditor $messageEditor,
        private CartKeyboard $cartKeyboard,
        private OrderKeyboard $orderKeyboard,
    ) {
    }

    public function cart(
        Nutgram $bot,
        OrderingBackendException $exception,
        string $context,
    ): void {
        if ($this->sessionRecovery->recoverIfUnauthorized(
            $bot,
            $exception,
        )) {
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

    public function orderCreation(
        Nutgram $bot,
        OrderingBackendException $exception,
        string $context,
    ): void {
        if ($this->sessionRecovery->recoverIfUnauthorized(
            $bot,
            $exception,
        )) {
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

    public function currentOrder(
        Nutgram $bot,
        OrderingBackendException $exception,
        string $context,
    ): void {
        if ($this->sessionRecovery->recoverIfUnauthorized(
            $bot,
            $exception,
        )) {
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

    public function payment(
        Nutgram $bot,
        OrderingBackendException $exception,
        string $context,
    ): void {
        if ($this->sessionRecovery->recoverIfUnauthorized(
            $bot,
            $exception,
        )) {
            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: 'Не вдалося оновити оплату. Спробуйте пізніше.',
            keyboard: $this->orderKeyboard->paymentPending($context),
        );
    }

    private function unprocessableOrderMessage(
        OrderingBackendException $exception,
    ): string {
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
}
