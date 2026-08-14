<?php

namespace App\Services\Otp;

use App\Services\Contracts\OtpSender;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LogOtpSender implements OtpSender
{
    public function send(string $phone, string $code): void
    {
        if (! App::environment(['local', 'testing'])) {
            throw new RuntimeException('Log OTP delivery is only allowed in local or testing environments.');
        }

        Log::info('Development OTP code generated.', [
            'phone' => $phone,
            'code' => $code,
        ]);
    }
}
