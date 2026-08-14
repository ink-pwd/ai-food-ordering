<?php

namespace App\Enums;

enum ReceivingType: string
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';
}
