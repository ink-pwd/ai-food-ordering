<?php

namespace App\Services\Support;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SessionSelection
{
    /** @param array<string, mixed> $session */
    public static function cityId(array $session): int
    {
        $cityId = $session['city_id'] ?? null;

        if (! is_int($cityId) || $cityId < 1) {
            throw new ConflictHttpException('City must be selected.');
        }

        return $cityId;
    }

    /** @param array<string, mixed> $session */
    public static function restaurantId(array $session): int
    {
        $restaurantId = $session['restaurant_id'] ?? null;

        if (! is_int($restaurantId) || $restaurantId < 1) {
            throw new ConflictHttpException('Restaurant must be selected.');
        }

        return $restaurantId;
    }

    /** @param array<string, mixed> $session */
    public static function assertContactExists(array $session): void
    {
        $contact = self::contact($session);

        if ($contact === null) {
            throw new ConflictHttpException('Contact information is required.');
        }
    }

    /** @param array<string, mixed> $session */
    public static function assertPhoneVerified(array $session): void
    {
        $contact = self::contact($session);

        if ($contact === null || ($contact['phone_verified'] ?? null) !== true) {
            throw new ConflictHttpException('Phone verification is required.');
        }
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array{name: string, phone: string, phone_verified?: bool}|null
     */
    public static function contact(array $session): ?array
    {
        $contact = $session['metadata']['contact'] ?? null;

        if (! is_array($contact)
            || ! is_string($contact['name'] ?? null)
            || trim($contact['name']) === ''
            || ! is_string($contact['phone'] ?? null)
            || trim($contact['phone']) === ''
        ) {
            return null;
        }

        return $contact;
    }
}
