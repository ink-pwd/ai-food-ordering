<?php

namespace App\Telegram\Support;

use App\DTO\OrderingBackend\DeliveryAddressData;

final class DeliveryAddressParser
{
    public function parse(
        string $text,
        int $type,
    ): ?DeliveryAddressData {
        $parts = array_values(array_filter(
            array_map(
                static fn (string $part): string => trim($part),
                explode(',', $text),
            ),
            static fn (string $part): bool => $part !== '',
        ));

        if (count($parts) < 2) {
            return null;
        }

        return new DeliveryAddressData(
            type: $type,
            street: $parts[0],
            house: $parts[1],
            flat: ($parts[2] ?? '') !== ''
                ? $parts[2]
                : null,
        );
    }
}
