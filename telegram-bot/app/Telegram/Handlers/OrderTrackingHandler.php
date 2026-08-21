<?php

namespace App\Telegram\Handlers;

use App\Telegram\CallbackAcknowledger;
use App\Telegram\Keyboards\MainMenuKeyboard;
use App\Telegram\TelegramMessageEditor;
use App\Telegram\Tracking\OrderTrackingContextResolver;
use App\Telegram\Tracking\OrderTrackingPresenter;
use App\Telegram\Tracking\OrderTrackingPrompt;
use SergiX44\Nutgram\Nutgram;

final readonly class OrderTrackingHandler
{
    public function __construct(
        private CallbackAcknowledger $callbackAcknowledger,
        private OrderTrackingContextResolver $contextResolver,
        private OrderTrackingPrompt $prompt,
        private OrderTrackingPresenter $presenter,
        private MainMenuKeyboard $mainMenuKeyboard,
        private TelegramMessageEditor $messageEditor,
    ) {
    }

    public function prompt(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): void {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->contextResolver->resolveCallback(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        $this->prompt->ask(
            $bot,
            $restaurantId,
            $context['sessionToken'],
        );
    }

    public function back(
        Nutgram $bot,
        int $restaurantId,
        string $fingerprint,
    ): void {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->contextResolver->resolveCallback(
            $bot,
            $restaurantId,
            $fingerprint,
        );

        if ($context === null) {
            return;
        }

        $chatId = $bot->chatId();

        if ($chatId !== null) {
            $this->prompt->forgetChat($chatId);
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: 'Вітаємо! Оберіть дію:',
            keyboard: $this->mainMenuKeyboard->make(
                $context['callbackContext'],
            ),
        );
    }

    public function handleInputIfExpected(
        Nutgram $bot,
        string $value,
    ): bool {
        $promptContext = $this->prompt->replyContext($bot);

        if ($promptContext === null) {
            return false;
        }

        $this->prompt->forget(
            $promptContext['chatId'],
            $promptContext['promptMessageId'],
        );

        $context = $this->contextResolver->resolveReply(
            $bot,
            $promptContext['restaurantId'],
            $promptContext['fingerprint'],
        );

        if ($context === null) {
            return true;
        }

        $orderId = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (! is_int($orderId)) {
            $this->prompt->ask(
                bot: $bot,
                restaurantId: $promptContext['restaurantId'],
                sessionToken: $context['sessionToken'],
                message: '⚠️ Номер замовлення має бути додатним числом. Спробуйте ще раз.',
            );

            return true;
        }

        $this->presenter->show(
            bot: $bot,
            sessionToken: $context['sessionToken'],
            restaurantId: $promptContext['restaurantId'],
            orderId: $orderId,
            callbackContext: $context['callbackContext'],
        );

        return true;
    }
}
