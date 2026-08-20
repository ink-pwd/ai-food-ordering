<?php

namespace App\DTO;

final readonly class SessionData
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $fulfillment
     */
    public function __construct(
        public string $id,
        public ?int $cityId,
        public ?int $restaurantId,
        public string $channel,
        public string $externalSessionId,
        public string $status,
        public array $metadata,
        public string $createdAt,
        public string $expiresAt,
        public ?array $fulfillment = null,
    ) {
    }

    /**
     * @param  array{id: string, city_id?: int|null, restaurant_id?: int|null, fulfillment?: array<string, mixed>|null, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}  $session
     */
    public static function fromArray(array $session): self
    {
        return new self(
            id: $session['id'],
            cityId: $session['city_id'] ?? null,
            restaurantId: $session['restaurant_id'] ?? null,
            channel: $session['channel'],
            externalSessionId: $session['external_session_id'],
            status: $session['status'],
            metadata: $session['metadata'],
            createdAt: $session['created_at'],
            expiresAt: $session['expires_at'],
            fulfillment: $session['fulfillment'] ?? null,
        );
    }
}
