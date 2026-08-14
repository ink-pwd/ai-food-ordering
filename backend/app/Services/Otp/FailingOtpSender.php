<?php

namespace App\Services\Otp;

use App\Services\Contracts\OtpSender;
use RuntimeException;

class FailingOtpSender implements OtpSender
{
    public function send(string $phone, string $code): void
    {
        throw new RuntimeException('OTP delivery provider is not configured.');
    }
}
