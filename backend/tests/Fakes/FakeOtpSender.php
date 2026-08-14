<?php

namespace Tests\Fakes;

use App\Services\Contracts\OtpSender;

class FakeOtpSender implements OtpSender
{
    /** @var array<int, array{phone: string, code: string}> */
    public array $deliveries = [];

    public function send(string $phone, string $code): void
    {
        $this->deliveries[] = [
            'phone' => $phone,
            'code' => $code,
        ];
    }

    public function lastCode(): string
    {
        return $this->deliveries[array_key_last($this->deliveries)]['code'];
    }
}
