<?php

use App\Enums\SessionChannel;
use App\Enums\SessionStatus;
use App\Services\Repositories\SessionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Carbon::setTestNow('2026-08-07 12:00:00');
    config()->set('services.internal.session_store', 'array');
    config()->set('services.internal.session_ttl_seconds', 60);
    config()->set('services.internal.session_key_prefix', 'test-session');
});

afterEach(function () {
    Carbon::setTestNow();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('stores and retrieves a session by token', function () {
    $session = sessionPayload();

    sessionRepository()->put('plain-session-token', $session);

    expect(sessionRepository()->findByToken('plain-session-token'))->toBe($session);
});

it('expires the session after the configured ttl', function () {
    config()->set('services.internal.session_ttl_seconds', 5);
    Carbon::setTestNow('2026-08-07 12:00:00');

    $plainToken = 'expiring-session-token';
    $session = sessionPayload();

    sessionRepository()->put($plainToken, $session);

    expect(sessionRepository()->findByToken($plainToken))->toBe($session);

    Carbon::setTestNow(now()->addSeconds(6));

    expect(sessionRepository()->findByToken($plainToken))->toBeNull();
});

it('deletes a session by token', function () {
    $plainToken = 'delete-session-token';

    sessionRepository()->put($plainToken, sessionPayload());

    expect(sessionRepository()->deleteByToken($plainToken))->toBeTrue()
        ->and(sessionRepository()->findByToken($plainToken))->toBeNull();
});

it('uses a sha256 token hash in the cache key', function () {
    $plainToken = 'hash-key-token';

    sessionRepository()->put($plainToken, sessionPayload());

    expect(Cache::store('array')->has('test-session:'.hash('sha256', $plainToken)))->toBeTrue();
});

it('never places the plain token in the cache key', function () {
    $plainToken = 'plain-token-must-not-be-in-key';
    $cacheKey = sessionCacheKey($plainToken);

    sessionRepository()->put($plainToken, sessionPayload());

    expect($cacheKey)->not->toContain($plainToken)
        ->and(Cache::store('array')->has($cacheKey))->toBeTrue();
});

it('never stores the plain token in the json payload', function () {
    $plainToken = 'plain-token-must-not-be-stored';

    sessionRepository()->put($plainToken, sessionPayload());

    expect(Cache::store('array')->get(sessionCacheKey($plainToken)))->not->toContain($plainToken);
});

it('updates session metadata and preserves unrelated metadata', function () {
    $plainToken = 'metadata-update-token';
    $session = sessionPayload([
        'metadata' => [
            'source' => 'chat',
            'contact' => ['name' => 'Old', 'phone' => '+380000000000'],
        ],
    ]);

    sessionRepository()->put($plainToken, $session);

    $updatedSession = sessionRepository()->updateMetadata($plainToken, [
        'contact' => ['name' => 'Yehor', 'phone' => '+380931234567', 'phone_verified' => false],
    ]);

    expect($updatedSession['metadata'])->toBe([
        'source' => 'chat',
        'contact' => ['name' => 'Yehor', 'phone' => '+380931234567', 'phone_verified' => false],
    ])->and(sessionRepository()->findByToken($plainToken))->toBe($updatedSession);
});

it('does not extend the cache ttl when updating metadata', function () {
    Carbon::setTestNow('2026-08-07 12:00:00');
    config()->set('services.internal.session_ttl_seconds', 5);

    $plainToken = 'metadata-ttl-token';
    $session = sessionPayload(['expires_at' => '2026-08-07T12:00:05+00:00']);

    sessionRepository()->put($plainToken, $session);

    Carbon::setTestNow(now()->addSeconds(2));

    sessionRepository()->updateMetadata($plainToken, ['contact' => ['name' => 'Yehor']]);

    Carbon::setTestNow(now()->addSeconds(4));

    expect(sessionRepository()->findByToken($plainToken))->toBeNull();
});

it('returns null and removes the cache entry when updating missing or expired sessions', function () {
    Carbon::setTestNow('2026-08-07 12:00:00');
    config()->set('services.internal.session_ttl_seconds', 60);

    $plainToken = 'metadata-expired-token';
    $session = sessionPayload(['expires_at' => '2026-08-07T12:00:01+00:00']);

    sessionRepository()->put($plainToken, $session);

    Carbon::setTestNow(now()->addSeconds(2));

    expect(sessionRepository()->updateMetadata('missing-token', ['contact' => ['name' => 'Yehor']]))->toBeNull()
        ->and(sessionRepository()->updateMetadata($plainToken, ['contact' => ['name' => 'Yehor']]))->toBeNull()
        ->and(Cache::store('array')->has(sessionCacheKey($plainToken)))->toBeFalse();
});

it('does not store the plain token when updating metadata', function () {
    $plainToken = 'metadata-plain-token-not-stored';

    sessionRepository()->put($plainToken, sessionPayload());
    sessionRepository()->updateMetadata($plainToken, ['contact' => ['name' => 'Yehor']]);

    expect(Cache::store('array')->get(sessionCacheKey($plainToken)))->not->toContain($plainToken);
});

function sessionRepository(): SessionRepository
{
    return app(SessionRepository::class);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{id: string, restaurant_id: int, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}
 */
function sessionPayload(array $overrides = []): array
{
    return array_replace([
        'id' => '01JZXYZSESSION000000000001',
        'restaurant_id' => 1,
        'channel' => SessionChannel::ChatGPT->value,
        'external_session_id' => 'external-conversation-id',
        'status' => SessionStatus::Active->value,
        'metadata' => [],
        'created_at' => '2026-08-07T12:00:00+00:00',
        'expires_at' => '2026-08-08T12:00:00+00:00',
    ], $overrides);
}

function sessionCacheKey(string $plainToken): string
{
    return config('services.internal.session_key_prefix').':'.hash('sha256', $plainToken);
}
