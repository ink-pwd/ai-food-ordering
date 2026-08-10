<?php

namespace App\Enums;

enum SessionChannel: string
{
    case ChatGPT = 'chatgpt';
    case Telegram = 'telegram';
    case Api = 'api';
}
