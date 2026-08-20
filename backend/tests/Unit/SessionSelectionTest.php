<?php

use App\DTO\SessionData;
use App\Services\Support\SessionSelection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

function sessionSelectionSession(?int $cityId = null, ?int $restaurantId = null, array $metadata = []): SessionData
{
    return new SessionData(
        id: 'session-1',
        cityId: $cityId,
        restaurantId: $restaurantId,
        channel: 'telegram',
        externalSessionId: 'chat-1',
        status: 'active',
        metadata: $metadata,
        createdAt: '2026-01-01T00:00:00+00:00',
        expiresAt: '2099-01-01T00:00:00+00:00',
    );
}

test('city selection returns a positive selected id', function (int $cityId): void {
    expect(SessionSelection::cityId(sessionSelectionSession(cityId: $cityId)))->toBe($cityId);
})->with([1, 42]);

test('city selection rejects an absent or non-positive id', function (?int $cityId): void {
    expect(fn () => SessionSelection::cityId(sessionSelectionSession(cityId: $cityId)))
        ->toThrow(ConflictHttpException::class, 'City must be selected.');
})->with([null, 0, -1]);

test('restaurant selection returns a positive selected id', function (int $restaurantId): void {
    expect(SessionSelection::restaurantId(sessionSelectionSession(restaurantId: $restaurantId)))->toBe($restaurantId);
})->with([1, 77]);

test('restaurant selection rejects an absent or non-positive id', function (?int $restaurantId): void {
    expect(fn () => SessionSelection::restaurantId(sessionSelectionSession(restaurantId: $restaurantId)))
        ->toThrow(ConflictHttpException::class, 'Restaurant must be selected.');
})->with([null, 0, -5]);

test('contact selection keeps valid contact data', function (array $contact): void {
    expect(SessionSelection::contact(sessionSelectionSession(metadata: ['contact' => $contact])))->toBe($contact);
})->with([
    'plain contact' => [['name' => 'Eduard', 'phone' => '+380931234567']],
    'verified contact' => [['name' => 'Eduard', 'phone' => '+380931234567', 'phone_verified' => true]],
    'unverified contact' => [['name' => 'Eduard', 'phone' => '+380931234567', 'phone_verified' => false]],
]);

test('contact selection rejects malformed contact data', function (mixed $contact): void {
    expect(SessionSelection::contact(sessionSelectionSession(metadata: ['contact' => $contact])))->toBeNull();
})->with([
    'not array' => ['Eduard'],
    'missing name' => [['phone' => '+380931234567']],
    'blank name' => [['name' => '   ', 'phone' => '+380931234567']],
    'missing phone' => [['name' => 'Eduard']],
    'blank phone' => [['name' => 'Eduard', 'phone' => '   ']],
]);

test('phone verification accepts a verified valid contact', function (): void {
    $session = sessionSelectionSession(metadata: [
        'contact' => ['name' => 'Eduard', 'phone' => '+380931234567', 'phone_verified' => true],
    ]);

    SessionSelection::assertPhoneVerified($session);

    expect(true)->toBeTrue();
});

test('phone verification rejects missing or unverified contact', function (array $metadata): void {
    expect(fn () => SessionSelection::assertPhoneVerified(sessionSelectionSession(metadata: $metadata)))
        ->toThrow(ConflictHttpException::class, 'Phone verification is required.');
})->with([
    'missing contact' => [[]],
    'missing flag' => [['contact' => ['name' => 'Eduard', 'phone' => '+380931234567']]],
    'false flag' => [['contact' => ['name' => 'Eduard', 'phone' => '+380931234567', 'phone_verified' => false]]],
]);
