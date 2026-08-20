<?php

namespace App\Services\Handlers\Synchronization;

use Illuminate\Validation\ValidationException;

class CompleteDotsDiscoveryListHandler
{
    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    public function handle(array $response, string $field): array
    {
        if (($response['hasNext'] ?? false) !== false) {
            throw ValidationException::withMessages([
                'hasNext' => [
                    'The Dots discovery response must be complete.',
                ],
            ]);
        }

        if (array_is_list($response)) {
            /** @var array<int, array<string, mixed>> $response */
            return $response;
        }

        $items = $response[$field]
            ?? $response['items']
            ?? null;

        if (! is_array($items) || ! array_is_list($items)) {
            throw ValidationException::withMessages([
                $field => [
                    'The Dots discovery response must contain a list.',
                ],
            ]);
        }

        /** @var array<int, array<string, mixed>> $items */
        return $items;
    }
}
