<?php

namespace App\Enums;

enum SessionStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
}
