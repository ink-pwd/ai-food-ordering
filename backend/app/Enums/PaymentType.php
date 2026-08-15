<?php

namespace App\Enums;

enum PaymentType: int
{
    case Cash = 1;
    case Online = 2;
    case Terminal = 3;

    public function requiresOnlinePayment(): bool
    {
        return $this === self::Online;
    }
}
