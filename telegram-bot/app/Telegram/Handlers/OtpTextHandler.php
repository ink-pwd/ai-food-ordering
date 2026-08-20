<?php

namespace App\Telegram\Handlers;

use SergiX44\Nutgram\Nutgram;

final readonly class OtpTextHandler
{
    public function __construct(
        private OtpHandler $otp,
    ) {
    }

    public function __invoke(Nutgram $bot, string $code): void
    {
        $this->otp->verify($bot, $code);
    }
}
