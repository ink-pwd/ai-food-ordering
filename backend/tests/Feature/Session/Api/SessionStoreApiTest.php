<?php

use App\Enums\SessionChannel;
use App\Services\Repositories\SessionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('services.internal.token', 'test-internal-token');
    config()->set('services.internal.session_store', 'array');
    config()->set('services.internal.session_ttl_seconds', 60);
    config()->set('services.internal.session_key_prefix', 'test-session');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('creates sessions for valid channels without a preselected restaurant', function (SessionChannel $channel) {
    authorizedSessionStoreRequest([
        'channel' => $channel->value,
        'external_session_id' => 'external-conversation-id',
    ])->assertCreated()
        ->assertJsonPath('data.channel', $channel->value)
        ->assertJsonPath('data.city', null)
        ->assertJsonPath('data.restaurant', null);
})->with([
    'chatgpt' => [SessionChannel::ChatGPT],
    'telegram' => [SessionChannel::Telegram],
    'api' => [SessionChannel::Api],
]);

it('rejects client provided restaurant and account fields', function (string $field) {
    authorizedSessionStoreRequest([
        'channel' => 'chatgpt',
        'external_session_id' => 'external-conversation-id',
        $field => 'client-controlled-value',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'restaurant_id' => ['restaurant_id'],
    'restaurant_slug' => ['restaurant_slug'],
    'account_id' => ['account_id'],
]);

it('rejects an invalid channel', function () {
    authorizedSessionStoreRequest([
        'channel' => 'web',
        'external_session_id' => 'external-conversation-id',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['channel']);
});

it('rejects a missing or oversized external session id', function (array $payload) {
    authorizedSessionStoreRequest($payload)->assertUnprocessable()
        ->assertJsonValidationErrors(['external_session_id']);
})->with([
    'missing' => [['channel' => 'chatgpt']],
    'oversized' => [['channel' => 'chatgpt', 'external_session_id' => str_repeat('a', 256)]],
]);

it('returns unauthorized when the internal token is missing', function () {
    $this->postJson(route('internal.sessions.store'), [
        'channel' => 'chatgpt',
        'external_session_id' => 'external-conversation-id',
    ])->assertUnauthorized()
        ->assertExactJson([
            'message' => 'Unauthenticated.',
        ]);
});

it('returns unauthorized when the internal token is wrong', function () {
    $this->withHeader('X-Internal-Api-Token', 'wrong-token')
        ->postJson(route('internal.sessions.store'), [
            'channel' => 'chatgpt',
            'external_session_id' => 'external-conversation-id',
        ])->assertUnauthorized()
        ->assertExactJson([
            'message' => 'Unauthenticated.',
        ]);
});

it('uses the configured ttl and expiration time', function () {
    Carbon::setTestNow('2026-08-07 12:00:00');
    config()->set('services.internal.session_ttl_seconds', 5);

    $response = authorizedSessionStoreRequest([
        'channel' => 'api',
        'external_session_id' => 'api-conversation-id',
    ])->assertCreated()
        ->assertJsonPath('data.expires_at', '2026-08-07T12:00:05+00:00');

    expect(persistedSessionFromResponse($response->json('data.session_token'))['expires_at'])->toBe('2026-08-07T12:00:05+00:00');

    Carbon::setTestNow(now()->addSeconds(6));

    expect(app(SessionRepository::class)->findByToken($response->json('data.session_token')))->toBeNull();
});

it('returns only safe response fields', function () {
    config()->set('services.dots.token', 'dots-public-token');
    config()->set('services.dots.account_token', 'dots-account-token');
    config()->set('services.dots.auth_token', 'dots-auth-token');
    $response = authorizedSessionStoreRequest([
        'channel' => 'chatgpt',
        'external_session_id' => 'secret-external-conversation-id',
    ])->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'session_id',
                'session_token',
                'channel',
                'status',
                'expires_at',
                'city',
                'restaurant',
            ],
        ]);

    expect($response->json('data'))->toHaveKeys([
        'session_id',
        'session_token',
        'channel',
        'status',
        'expires_at',
        'city',
        'restaurant',
    ])->and(array_keys($response->json('data')))->toBe([
        'session_id',
        'session_token',
        'channel',
        'status',
        'expires_at',
        'city',
        'restaurant',
    ])->and($response->json('data.city'))->toBeNull()
        ->and($response->json('data.restaurant'))->toBeNull()->and($response->getContent())
        ->not->toContain('restaurant_id')
        ->not->toContain('external_session_id')
        ->not->toContain('secret-external-conversation-id')
        ->not->toContain('external_company_id')
        ->not->toContain('11111111-1111-1111-1111-111111111111')
        ->not->toContain('metadata')
        ->not->toContain('created_at')
        ->not->toContain('test-internal-token')
        ->not->toContain('dots-public-token')
        ->not->toContain('dots-account-token')
        ->not->toContain('dots-auth-token')
        ->not->toContain('INTERNAL_RESTAURANT_SLUG');
});

function authorizedSessionStoreRequest(array $payload): TestResponse
{
    return test()->withHeader('X-Internal-Api-Token', 'test-internal-token')
        ->postJson(route('internal.sessions.store'), $payload);
}

function persistedSessionFromResponse(string $plainToken): array
{
    return json_decode(
        Cache::store('array')->get(sessionStoreCacheKey($plainToken)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function sessionStoreCacheKey(string $plainToken): string
{
    return config('services.internal.session_key_prefix').':'.hash('sha256', $plainToken);
}
