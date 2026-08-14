<?php

namespace App\Enums;

enum CartStatus: string
{
    case Active = 'active';
    case CheckedOut = 'checked_out';
    case Expired = 'expired';
    case Abandoned = 'abandoned';
}
