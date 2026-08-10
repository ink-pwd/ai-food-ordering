<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Creating = 'creating';
    case Created = 'created';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
