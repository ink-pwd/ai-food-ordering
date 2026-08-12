<?php

namespace App\Mcp\Support;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class InvalidSessionContextException extends RuntimeException implements ShouldntReport
{
    public static function malformed(): self
    {
        return new self('Контекст заказа недействителен или истёк. Создайте новый контекст заказа.');
    }

    public static function expired(): self
    {
        return new self('Контекст заказа недействителен или истёк. Создайте новый контекст заказа.');
    }
}
