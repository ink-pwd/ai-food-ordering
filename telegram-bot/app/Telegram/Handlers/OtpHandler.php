<?php

namespace App\Telegram\Handlers;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\CallbackAcknowledger;
use App\Telegram\Keyboards\OtpKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final class OtpHandler
{
    public function __construct(
        private readonly CallbackAcknowledger $callbackAcknowledger,
        private readonly TelegramSessionRecovery $sessionRecovery,
        private readonly OrderingBackendClient $backend,
        private readonly OnboardingFlow $onboarding,
        private readonly OtpKeyboard $otpKeyboard,
        private readonly TelegramMessageEditor $messageEditor,
    ) {}

    public function verify(Nutgram $bot, string $code): void
    {
        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        try {
            $this->backend->verifyCurrentSessionOtp($sessionToken, $code);
        } catch (OrderingBackendException $exception) {
            $this->handleVerificationFailure($bot, $exception);

            return;
        }

        $bot->sendMessage(text: '✅ Номер телефону підтверджено.');
        $this->onboarding->renderCities($bot, $sessionToken);
    }

    public function resend(Nutgram $bot): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        try {
            $this->backend->requestCurrentSessionOtp($sessionToken);
        } catch (OrderingBackendException $exception) {
            $this->handleResendFailure($bot, $exception);

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: '🔐 Новий код надіслано. Введіть його повідомленням у цей чат.',
            keyboard: $this->otpKeyboard->make(),
        );
    }

    private function handleVerificationFailure(Nutgram $bot, OrderingBackendException $exception): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        $message = match ($exception->statusCode()) {
            409, 422 => '❌ Невірний код. Спробуйте ще раз.',
            default => 'Сервіс тимчасово недоступний. Спробуйте пізніше.',
        };

        $bot->sendMessage(
            text: $message,
            reply_markup: $this->otpKeyboard->make(),
        );
    }

    private function handleResendFailure(Nutgram $bot, OrderingBackendException $exception): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        $message = match ($exception->statusCode()) {
            409 => '⏳ Повторно надіслати код можна трохи пізніше.',
            422 => 'Не вдалося надіслати код. Перевірте номер телефону та спробуйте ще раз.',
            default => 'Сервіс тимчасово недоступний. Спробуйте пізніше.',
        };

        $this->messageEditor->edit(
            bot: $bot,
            text: $message,
            keyboard: $this->otpKeyboard->make(),
        );
    }
}
