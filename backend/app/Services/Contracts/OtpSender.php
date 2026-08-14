<?php

namespace App\Services\Contracts;

interface OtpSender
{
    public function send(string $phone, string $code): void;
}
