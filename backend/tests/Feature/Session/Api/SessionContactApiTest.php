<?php

use App\Enums\SessionChannel;
use App\Enums\SessionStatus;
use App\Services\Handlers\Session\UpdateSessionContactHandler;
use App\Services\Repositories\SessionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Carbon::setTestNow('2026-08-07 12:00:00');
    config()->set('services.internal.token', 'test-internal-token');
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

it('normalizes common ukrainian phone formats', function (string $phone) {
    storeContactApiSession(contactApiToken());

    authorizedContactRequest(['name' => 'Yehor', 'phone' => $phone])
        ->assertOk()
        ->assertJsonPath('data.contact.phone', '+380931234567');

    expect(contactApiPersistedSession(contactApiToken())['metadata']['contact']['phone'])->toBe('+380931234567');
})->with([
    '+380931234567' => ['+380931234567'],
    '380931234567' => ['380931234567'],
    '0931234567' => ['0931234567'],
    '00380931234567' => ['00380931234567'],
    '+380 93 123 45 67' => ['+380 93 123 45 67'],
    '+380 separators' => ['+380 (93) 123-45-67'],
]);

it('accepts a valid international e164 phone', function () {
    storeContactApiSession(contactApiToken());

    authorizedContactRequest(['name' => 'Yehor', 'phone' => '+14155552671'])
        ->assertOk()
        ->assertJsonPath('data.contact.phone', '+14155552671');
});

it('rejects invalid letters and malformed phone numbers', function (string $phone) {
    storeContactApiSession(contactApiToken());

    authorizedContactRequest(['name' => 'Yehor', 'phone' => $phone])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['phone']);
})->with([
    'letters' => ['+38093abc4567'],
    'slash' => ['+380/931234567'],
    'too short' => ['+1234567'],
    'starts with zero after plus' => ['+0123456789'],
    'missing plus international' => ['14155552671'],
]);

it('rejects missing or oversized name and phone', function (array $payload, string $field) {
    storeContactApiSession(contactApiToken());

    authorizedContactRequest($payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'missing name' => [['phone' => '+380931234567'], 'name'],
    'oversized name' => [['name' => str_repeat('a', 101), 'phone' => '+380931234567'], 'name'],
    'missing phone' => [['name' => 'Yehor'], 'phone'],
    'oversized phone' => [['name' => 'Yehor', 'phone' => str_repeat('1', 33)], 'phone'],
]);

it('rejects prohibited client fields', function (string $field) {
    storeContactApiSession(contactApiToken());

    authorizedContactRequest([
        'name' => 'Yehor',
        'phone' => '+380931234567',
        $field => 'client-controlled-value',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'phone_verified' => ['phone_verified'],
    'customer_id' => ['customer_id'],
    'restaurant_id' => ['restaurant_id'],
    'restaurant_slug' => ['restaurant_slug'],
    'account_id' => ['account_id'],
]);

it('returns unauthorized when the internal token is missing or wrong', function (?string $token) {
    storeContactApiSession(contactApiToken());

    $request = $token === null
        ? $this->withHeader('X-Session-Token', contactApiToken())
        : $this->withHeaders(['X-Internal-Api-Token' => $token, 'X-Session-Token' => contactApiToken()]);

    $request->putJson(route('internal.sessions.contact.update'), [
        'name' => 'Yehor',
        'phone' => '+380931234567',
    ])->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
})->with([
    'missing' => [null],
    'wrong' => ['wrong-token'],
]);

it('returns unauthorized when the session token is missing invalid or expired', function (string $case) {
    if ($case === 'expired') {
        Carbon::setTestNow('2026-08-07 12:00:00');
        config()->set('services.internal.session_ttl_seconds', 1);
        storeContactApiSession(contactApiToken());
        Carbon::setTestNow(now()->addSeconds(2));
    }

    $headers = ['X-Internal-Api-Token' => 'test-internal-token'];

    if ($case === 'invalid') {
        $headers['X-Session-Token'] = 'invalid-token';
    } elseif ($case === 'expired') {
        $headers['X-Session-Token'] = contactApiToken();
    }

    $this->withHeaders($headers)
        ->putJson(route('internal.sessions.contact.update'), [
            'name' => 'Yehor',
            'phone' => '+380931234567',
        ])->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
})->with([
    'missing' => ['missing'],
    'invalid' => ['invalid'],
    'expired' => ['expired'],
]);

it('returns generic unauthorized when the session disappears before persistence', function () {
    storeContactApiSession(contactApiToken());

    $this->mock(UpdateSessionContactHandler::class, function ($mock): void {
        $mock->shouldReceive('handle')->once()->andReturn(null);
    });

    authorizedContactRequest(['name' => 'Yehor', 'phone' => '+380931234567'])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('does not return session token or hidden fields', function () {
    config()->set('services.dots.token', 'dots-public-token');
    config()->set('services.dots.account_token', 'dots-account-token');
    config()->set('services.dots.auth_token', 'dots-auth-token');
    storeContactApiSession(contactApiToken(), [
        'restaurant_id' => 999999,
        'external_session_id' => 'secret-external-session-id',
        'metadata' => ['source' => 'chatgpt'],
    ]);

    $response = authorizedContactRequest(['name' => 'Yehor', 'phone' => '+380931234567'])
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'session_id',
                'contact' => ['name', 'phone', 'phone_verified'],
            ],
        ]);

    expect(array_keys($response->json('data')))->toBe(['session_id', 'contact'])
        ->and(array_keys($response->json('data.contact')))->toBe(['name', 'phone', 'phone_verified'])
        ->and($response->getContent())
        ->not->toContain(contactApiToken())
        ->not->toContain('restaurant_id')
        ->not->toContain('999999')
        ->not->toContain('external_session_id')
        ->not->toContain('secret-external-session-id')
        ->not->toContain('metadata')
        ->not->toContain('source')
        ->not->toContain('created_at')
        ->not->toContain('expires_at')
        ->not->toContain('test-internal-token')
        ->not->toContain('dots-public-token')
        ->not->toContain('dots-account-token')
        ->not->toContain('dots-auth-token');
});

function authorizedContactRequest(array $payload): TestResponse
{
    return test()->withHeaders([
        'X-Internal-Api-Token' => 'test-internal-token',
        'X-Session-Token' => contactApiToken(),
    ])->putJson(route('internal.sessions.contact.update'), $payload);
}

function storeContactApiSession(string $plainToken, array $overrides = []): void
{
    app(SessionRepository::class)->put($plainToken, contactApiSessionPayload($overrides));
}

function contactApiPersistedSession(string $plainToken): array
{
    return json_decode(
        Cache::store('array')->get(contactApiCacheKey($plainToken)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function contactApiSessionPayload(array $overrides = []): array
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

function contactApiCacheKey(string $plainToken): string
{
    return config('services.internal.session_key_prefix').':'.hash('sha256', $plainToken);
}

function contactApiToken(): string
{
    return str_repeat('c', 64);
}
