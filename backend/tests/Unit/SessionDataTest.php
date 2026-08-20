<?php

use App\DTO\SessionData;

test('session data maps persisted session fields explicitly', function (): void {
    $session = SessionData::fromArray([
        'id' => '01KSESSION',
        'city_id' => 7,
        'restaurant_id' => 11,
        'channel' => 'telegram',
        'external_session_id' => 'chat-123',
        'status' => 'active',
        'metadata' => ['contact' => ['name' => 'Test User']],
        'created_at' => '2026-08-20T10:00:00+00:00',
        'expires_at' => '2026-08-20T11:00:00+00:00',
        'fulfillment' => ['type' => 'pickup', 'restaurant_address_id' => 5],
    ]);

    expect($session->id)->toBe('01KSESSION')
        ->and($session->cityId)->toBe(7)
        ->and($session->restaurantId)->toBe(11)
        ->and($session->channel)->toBe('telegram')
        ->and($session->externalSessionId)->toBe('chat-123')
        ->and($session->status)->toBe('active')
        ->and($session->metadata)->toBe(['contact' => ['name' => 'Test User']])
        ->and($session->fulfillment)->toBe(['type' => 'pickup', 'restaurant_address_id' => 5]);
});

test('session data keeps optional selection fields nullable', function (): void {
    $session = SessionData::fromArray([
        'id' => '01KSESSION',
        'channel' => 'telegram',
        'external_session_id' => 'chat-123',
        'status' => 'active',
        'metadata' => [],
        'created_at' => '2026-08-20T10:00:00+00:00',
        'expires_at' => '2026-08-20T11:00:00+00:00',
    ]);

    expect($session->cityId)->toBeNull()
        ->and($session->restaurantId)->toBeNull()
        ->and($session->fulfillment)->toBeNull();
});
