<?php

namespace App\Telegram\Support;

final class RestaurantNavigationContext
{
    public const STALE_MESSAGE = '⚠️ Це меню вже застаріло. Почніть з актуального меню.';

    public function encode(int $restaurantId, string $sessionToken): string
    {
        return $restaurantId.':'.$this->fingerprint($sessionToken);
    }

    public function fingerprint(string $sessionToken): string
    {
        return substr(hash('sha256', $sessionToken), 0, 12);
    }

    public function isValid(int $restaurantId, string $fingerprint, string $sessionToken): bool
    {
        return $restaurantId > 0 && hash_equals($this->fingerprint($sessionToken), $fingerprint);
    }
}
