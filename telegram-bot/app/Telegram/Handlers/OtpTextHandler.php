<?php

namespace App\Telegram\Handlers;

use SergiX44\Nutgram\Nutgram;

final readonly class OtpTextHandler
{
    public function __construct(
        private OtpHandler $otp,
        private OrderTrackingHandler $orderTracking,
        private AiAssistantHandler $aiAssistant,
    ) {
    }

    public function __invoke(Nutgram $bot, string $code): void
    {
        if ($this->orderTracking->handleInputIfExpected($bot, $code)) {
            return;
        }

        if ($this->aiAssistant->handleInputIfExpected($bot, $code)) {
            return;
        }

        $this->otp->verify($bot, $code);
    }
}
