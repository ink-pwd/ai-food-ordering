<?php

namespace App\Telegram\Tracking;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\Formatting\OrderTrackingMessageFormatter;
use App\Telegram\Keyboards\MainMenuKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use SergiX44\Nutgram\Nutgram;

final readonly class OrderTrackingPresenter
{
    public function __construct(
        private TelegramSessionRecovery $sessionRecovery,
        private OrderingBackendClient $backend,
        private OrderTrackingMessageFormatter $formatter,
        private MainMenuKeyboard $mainMenuKeyboard,
        private OrderTrackingPrompt $prompt,
    ) {
    }

    public function show(
        Nutgram $bot,
        string $sessionToken,
        int $restaurantId,
        int $orderId,
        string $callbackContext,
    ): void {
        try {
            $tracking = $this->backend->orderTracking(
                $sessionToken,
                $orderId,
            );
        } catch (OrderingBackendException $exception) {
            if ($this->sessionRecovery->recoverIfUnauthorized(
                $bot,
                $exception,
            )) {
                return;
            }

            if ($exception->statusCode() === 404) {
                $this->prompt->ask(
                    bot: $bot,
                    restaurantId: $restaurantId,
                    sessionToken: $sessionToken,
                    message: "⚠️ Замовлення #{$orderId} не знайдено. Перевірте номер і спробуйте ще раз.",
                );

                return;
            }

            $bot->sendMessage(
                text: '⚠️ Не вдалося отримати інформацію про замовлення. Спробуйте пізніше.',
                reply_markup: $this->mainMenuKeyboard->make(
                    $callbackContext,
                ),
            );

            return;
        }

        $bot->sendMessage(
            text: $this->formatter->format($tracking),
            reply_markup: $this->mainMenuKeyboard->make(
                $callbackContext,
            ),
        );
    }
}
