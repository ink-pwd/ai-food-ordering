<?php

use App\Enums\SessionChannel;
use App\Enums\SessionStatus;
use App\Models\City;
use App\Services\Contracts\OtpSender;
use App\Services\Repositories\OtpChallengeRepository;
use App\Services\Repositories\SessionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeOtpSender;

beforeEach(function () {
    Carbon::setTestNow('2026-08-14 12:00:00');
    config()->set('services.internal.token', 'test-internal-token');
    config()->set('services.internal.session_store', 'array');
    config()->set('services.internal.session_ttl_seconds', 3600);
    config()->set('services.internal.session_key_prefix', 'test-session-otp');
    config()->set('services.internal.otp.delivery_driver', 'log');
    config()->set('services.internal.otp.store', 'array');
    config()->set('services.internal.otp.key_prefix', 'test-session-otp-challenge');
    config()->set('services.internal.otp.code_length', 6);
    config()->set('services.internal.otp.ttl_seconds', 300);
    config()->set('services.internal.otp.resend_cooldown_seconds', 60);
    config()->set('services.internal.otp.max_attempts', 2);

    $this->otpSender = new FakeOtpSender;
    app()->instance(OtpSender::class, $this->otpSender);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('requests otp for existing contact and does not expose the raw code', function () {
    storeOtpSession(otpToken(), otpSessionWithContact());

    $response = otpPost(route('internal.sessions.otp.store'))
        ->assertOk()
        ->assertJsonStructure(['data' => ['expires_in', 'resend_available_in']]);

    $challenge = app(OtpChallengeRepository::class)->find(otpSessionWithContact()['id']);

    expect($this->otpSender->deliveries)->toHaveCount(1)
        ->and($this->otpSender->deliveries[0]['phone'])->toBe('+380931234567')
        ->and($response->getContent())->not->toContain($this->otpSender->lastCode())
        ->and($challenge['code_hash'])->not->toBe($this->otpSender->lastCode())
        ->and($challenge['phone'])->toBe('+380931234567');
});

it('rejects immediate resend without sending another otp', function () {
    storeOtpSession(otpToken(), otpSessionWithContact());

    otpPost(route('internal.sessions.otp.store'))->assertOk();
    otpPost(route('internal.sessions.otp.store'))->assertConflict();

    expect($this->otpSender->deliveries)->toHaveCount(1);
});

it('verifies correct otp and consumes the challenge', function () {
    storeOtpSession(otpToken(), otpSessionWithContact());
    otpPost(route('internal.sessions.otp.store'))->assertOk();

    otpPost(route('internal.sessions.otp.verify'), ['code' => $this->otpSender->lastCode()])
        ->assertOk()
        ->assertJsonPath('data.contact.phone_verified', true);

    $session = app(SessionRepository::class)->findByToken(otpToken());

    expect($session['metadata']['contact']['phone_verified'])->toBeTrue()
        ->and(app(OtpChallengeRepository::class)->find($session['id']))->toBeNull();

    otpPost(route('internal.sessions.otp.verify'), ['code' => $this->otpSender->lastCode()])
        ->assertConflict();
});

it('limits incorrect otp attempts without verifying the phone', function () {
    storeOtpSession(otpToken(), otpSessionWithContact());
    otpPost(route('internal.sessions.otp.store'))->assertOk();

    otpPost(route('internal.sessions.otp.verify'), ['code' => '000000'])->assertUnprocessable();
    $challenge = app(OtpChallengeRepository::class)->find(otpSessionWithContact()['id']);

    expect($challenge['attempts_remaining'])->toBe(1);

    otpPost(route('internal.sessions.otp.verify'), ['code' => '111111'])->assertUnprocessable();

    expect(app(SessionRepository::class)->findByToken(otpToken())['metadata']['contact']['phone_verified'])->toBeFalse()
        ->and(app(OtpChallengeRepository::class)->find(otpSessionWithContact()['id']))->toBeNull();
});

it('contact changes reset verification and invalidate outstanding otp', function () {
    storeOtpSession(otpToken(), otpSessionWithContact([
        'phone_verified' => true,
    ]));
    otpPost(route('internal.sessions.otp.store'))->assertConflict();

    app(OtpChallengeRepository::class)->put(otpSessionWithContact()['id'], [
        'session_id' => otpSessionWithContact()['id'],
        'phone' => '+380931234567',
        'code_hash' => 'hash',
        'attempts_remaining' => 2,
        'expires_at' => now()->addMinutes(5)->toIso8601String(),
        'resend_available_at' => now()->addMinute()->toIso8601String(),
    ]);

    otpPut(route('internal.sessions.contact.update'), [
        'name' => 'Yehor',
        'phone' => '+14155552671',
    ])->assertOk()
        ->assertJsonPath('data.contact.phone_verified', false)
        ->assertJsonPath('data.contact.phone', '+14155552671');

    $session = app(SessionRepository::class)->findByToken(otpToken());

    expect($session['metadata']['contact']['phone_verified'])->toBeFalse()
        ->and(app(OtpChallengeRepository::class)->find($session['id']))->toBeNull();
});

it('requires verified phone before city selection and allows it after verification', function () {
    $city = City::factory()->create();
    storeOtpSession(otpToken(), otpSessionWithContact());

    otpPut(route('internal.sessions.city.update'), ['city_id' => $city->id])->assertConflict();

    otpPost(route('internal.sessions.otp.store'))->assertOk();
    otpPost(route('internal.sessions.otp.verify'), ['code' => $this->otpSender->lastCode()])->assertOk();

    otpPut(route('internal.sessions.city.update'), ['city_id' => $city->id])
        ->assertOk()
        ->assertJsonPath('data.city.id', $city->id);
});

it('exit removes outstanding otp challenge', function () {
    storeOtpSession(otpToken(), otpSessionWithContact());
    otpPost(route('internal.sessions.otp.store'))->assertOk();

    otpDelete(route('internal.sessions.current.destroy'))->assertOk();

    expect(app(OtpChallengeRepository::class)->find(otpSessionWithContact()['id']))->toBeNull();
});

function otpPost(string $uri, array $payload = []): TestResponse
{
    return test()->withHeaders(otpHeaders())->postJson($uri, $payload);
}

function otpPut(string $uri, array $payload = []): TestResponse
{
    return test()->withHeaders(otpHeaders())->putJson($uri, $payload);
}

function otpDelete(string $uri): TestResponse
{
    return test()->withHeaders(otpHeaders())->deleteJson($uri);
}

function otpHeaders(): array
{
    return [
        'X-Internal-Api-Token' => 'test-internal-token',
        'X-Session-Token' => otpToken(),
    ];
}

function storeOtpSession(string $plainToken, array $session): void
{
    app(SessionRepository::class)->put($plainToken, $session);
}

function otpSessionWithContact(array $contactOverrides = [], array $sessionOverrides = []): array
{
    return array_replace([
        'id' => '01JZXYZSESSION000000000OTP',
        'city_id' => null,
        'restaurant_id' => null,
        'channel' => SessionChannel::ChatGPT->value,
        'external_session_id' => 'external-conversation-id',
        'status' => SessionStatus::Active->value,
        'metadata' => [
            'contact' => array_replace([
                'name' => 'Yehor',
                'phone' => '+380931234567',
                'phone_verified' => false,
            ], $contactOverrides),
        ],
        'created_at' => '2026-08-14T12:00:00+00:00',
        'expires_at' => '2026-08-14T13:00:00+00:00',
    ], $sessionOverrides);
}

function otpToken(): string
{
    return str_repeat('a', 64);
}
