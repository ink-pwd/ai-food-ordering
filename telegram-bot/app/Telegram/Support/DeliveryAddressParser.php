<?php

namespace App\Telegram\Support;

final class DeliveryAddressParser
{
    /**
     * @return array{type: int, street: string, house: string, flat?: string}|null
     */
    public function parse(string $text, int $type): ?array
    {
        $parts = array_values(array_filter(
            array_map(static fn (string $part): string => trim($part), explode(',', $text)),
            static fn (string $part): bool => $part !== '',
        ));

        if (count($parts) < 2) {
            return null;
        }

        $payload = [
            'type' => $type,
            'street' => $parts[0],
            'house' => $parts[1],
        ];

        if (($parts[2] ?? '') !== '') {
            $payload['flat'] = $parts[2];
        }

        return $payload;
    }
}
