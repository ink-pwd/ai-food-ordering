<?php

namespace App\Services\Support;

use App\DTO\SessionData;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SessionSelection
{
    public static function cityId(SessionData $session): int
    {
        $cityId = $session->cityId;

        if (! is_int($cityId) || $cityId < 1) {
            throw new ConflictHttpException(
                'City must be selected.',
            );
        }

        return $cityId;
    }

    public static function restaurantId(SessionData $session): int
    {
        $restaurantId = $session->restaurantId;

        if (! is_int($restaurantId) || $restaurantId < 1) {
            throw new ConflictHttpException(
                'Restaurant must be selected.',
            );
        }

        return $restaurantId;
    }

    public static function assertContactExists(
        SessionData $session,
    ): void {
        $contact = self::contact($session);

        if ($contact === null) {
            throw new ConflictHttpException(
                'Contact information is required.',
            );
        }
    }

    public static function assertPhoneVerified(
        SessionData $session,
    ): void {
        $contact = self::contact($session);

        if (
            $contact === null
            || ($contact['phone_verified'] ?? null) !== true
        ) {
            throw new ConflictHttpException(
                'Phone verification is required.',
            );
        }
    }

    /**
     * @return array{name: string, phone: string, phone_verified?: bool}|null
     */
    public static function contact(
        SessionData $session,
    ): ?array {
        $contact = $session->metadata['contact'] ?? null;

        if (self::isInvalidContact($contact)) {
            return null;
        }

        /** @var array{name: string, phone: string, phone_verified?: bool} $contact */
        return $contact;
    }

    private static function isInvalidContact(
        mixed $contact,
    ): bool {
        return ! is_array($contact)
            || ! is_string($contact['name'] ?? null)
            || trim($contact['name']) === ''
            || ! is_string($contact['phone'] ?? null)
            || trim($contact['phone']) === '';
    }
}
