<?php

namespace App\Telegram\Handlers;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\ContactOnboarding;
use App\Telegram\Session\TelegramSessionRecovery;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class ContactHandler
{
    public function __construct(
        private readonly TelegramSessionRecovery $sessionRecovery,
        private readonly OrderingBackendClient $backend,
        private readonly ContactOnboarding $onboarding,
        private readonly OnboardingFlow $onboardingFlow,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $contact = $bot->message()?->contact;
        $sender = $bot->user();

        if ($contact?->user_id === null || $sender === null || $contact->user_id !== $sender->id) {
            $this->onboarding->request($bot, 'Будь ласка, надішліть свій власний номер телефону.');

            return;
        }

        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return;
        }

        try {
            $this->backend->updateCurrentSessionContact(
                sessionToken: $sessionToken,
                name: $this->telegramName($sender),
                phone: $contact->phone_number,
            );
        } catch (OrderingBackendException $exception) {
            $this->handleBackendFailure($bot, $exception);

            return;
        }

        $bot->sendMessage(
            text: '✅ Номер телефону отримано.',
            reply_markup: ReplyKeyboardRemove::make(remove_keyboard: true),
        );

        $this->onboardingFlow->requestOtp($bot, $sessionToken);
    }

    private function telegramName(User $sender): string
    {
        return Str::of("{$sender->first_name} {$sender->last_name}")
            ->squish()
            ->toString();
    }

    private function handleBackendFailure(Nutgram $bot, OrderingBackendException $exception): void
    {
        if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
            return;
        }

        if ($exception->statusCode() === 422) {
            $this->onboarding->request(
                $bot,
                'Не вдалося прийняти номер телефону. Перевірте його та спробуйте ще раз.',
            );

            return;
        }

        $this->onboarding->reportTemporaryFailure($bot);
    }
}
