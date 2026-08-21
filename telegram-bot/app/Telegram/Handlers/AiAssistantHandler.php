<?php

namespace App\Telegram\Handlers;

use App\Exceptions\LlmException;
use App\Exceptions\OrderingBackendException;
use App\Services\Ai\AiAssistant;
use App\Telegram\Ai\AiContextResolver;
use App\Telegram\Ai\AiPrompt;
use App\Telegram\CallbackAcknowledger;
use App\Telegram\Keyboards\MainMenuKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\Support\AiConversationStore;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final readonly class AiAssistantHandler
{
    public function __construct(
        private CallbackAcknowledger $callbackAcknowledger,
        private AiContextResolver $contextResolver,
        private AiPrompt $prompt,
        private AiAssistant $assistant,
        private AiConversationStore $conversations,
        private TelegramSessionRecovery $sessionRecovery,
        private MainMenuKeyboard $mainMenuKeyboard,
        private TelegramMessageEditor $messageEditor,
    ) {
    }

    public function open(
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
            $this->conversations->forget($chatId);
        }

        $this->prompt->ask(
            bot: $bot,
            restaurantId: $restaurantId,
            sessionToken: $context->sessionToken,
            message: "🤖 AI-помічник\n\nЯ можу знайти товари, сформувати кошик або перевірити замовлення за його номером. Оформлення замовлення та оплата залишаються тільки вручну.",
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
            $this->conversations->forget($chatId);
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: 'Вітаємо! Оберіть дію:',
            keyboard: $this->mainMenuKeyboard->make(
                $context->callbackContext,
            ),
        );
    }

    public function handleInputIfExpected(
        Nutgram $bot,
        string $message,
    ): bool {
        $promptContext = $this->prompt->replyContext($bot);

        if ($promptContext === null) {
            return false;
        }

        $this->prompt->forget(
            $promptContext->chatId,
            $promptContext->promptMessageId,
        );

        $context = $this->contextResolver->resolveReply(
            $bot,
            $promptContext->restaurantId,
            $promptContext->fingerprint,
        );

        if ($context === null) {
            return true;
        }

        try {
            $answer = $this->assistant->reply(
                $promptContext->chatId,
                $message,
                $context,
            );
        } catch (OrderingBackendException $exception) {
            if ($this->sessionRecovery->recoverIfUnauthorized(
                $bot,
                $exception,
            )) {
                return true;
            }

            $this->repeatAfterFailure(
                $bot,
                $promptContext->restaurantId,
                $context->sessionToken,
                '⚠️ Не вдалося отримати дані для AI-помічника. Спробуйте ще раз.',
            );

            return true;
        } catch (LlmException) {
            $this->repeatAfterFailure(
                $bot,
                $promptContext->restaurantId,
                $context->sessionToken,
                '⚠️ AI-помічник тимчасово недоступний. Спробуйте ще раз.',
            );

            return true;
        }

        $bot->sendMessage(text: $answer);
        $this->prompt->ask(
            $bot,
            $promptContext->restaurantId,
            $context->sessionToken,
            '🤖 Що ще зробити?',
        );

        return true;
    }

    private function repeatAfterFailure(
        Nutgram $bot,
        int $restaurantId,
        string $sessionToken,
        string $message,
    ): void {
        $this->prompt->ask(
            bot: $bot,
            restaurantId: $restaurantId,
            sessionToken: $sessionToken,
            message: $message,
        );
    }
}
