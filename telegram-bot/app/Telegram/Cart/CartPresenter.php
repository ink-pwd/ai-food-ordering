<?php

namespace App\Telegram\Cart;

use App\DTO\OrderingBackend\CartData;
use App\Exceptions\OrderingBackendException;
use App\Telegram\Formatting\CartMessageFormatter;
use App\Telegram\Keyboards\CartKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final readonly class CartPresenter
{
    public function __construct(
        private TelegramSessionRecovery $sessionRecovery,
        private CartMessageFormatter $formatter,
        private CartKeyboard $keyboard,
        private TelegramMessageEditor $messageEditor,
    ) {
    }

    public function render(
        Nutgram $bot,
        CartData $cart,
        string $context,
    ): void {
        $this->messageEditor->edit(
            bot: $bot,
            text: $this->formatter->format($cart),
            keyboard: $this->keyboard->make(
                $cart->items,
                $cart->status,
                $context,
            ),
        );
    }

    public function renderMissingItem(
        Nutgram $bot,
        CartData $cart,
        string $context,
    ): void {
        $this->messageEditor->edit(
            bot: $bot,
            text: $this->formatter->formatWithNotice(
                $cart,
                'Товар уже відсутній у кошику.',
            ),
            keyboard: $this->keyboard->make(
                $cart->items,
                $cart->status,
                $context,
            ),
        );
    }

    public function failure(
        Nutgram $bot,
        OrderingBackendException $exception,
        string $notFoundMessage,
        string $unprocessableMessage,
        string $context,
    ): void {
        if ($this->sessionRecovery->recoverIfUnauthorized(
            $bot,
            $exception,
        )) {
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
            keyboard: $this->keyboard->make(
                context: $context,
            ),
        );
    }
}
