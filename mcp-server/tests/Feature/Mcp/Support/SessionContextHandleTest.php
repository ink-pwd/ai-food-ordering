<?php

use App\Mcp\Support\BackendSessionContext;
use App\Mcp\Support\InvalidSessionContextException;
use App\Mcp\Support\SessionContextHandle;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('round-trips a valid encrypted backend session context', function () {
    $expiresAt = CarbonImmutable::now()->addHour()->startOfSecond();
    $context = backendSessionContext($expiresAt);

    $restoredContext = app(SessionContextHandle::class)->restore(
        app(SessionContextHandle::class)->issue($context),
    );

    expect($restoredContext->backendSessionId())->toBe('backend-session-id')
        ->and($restoredContext->backendSessionToken())->toBe('backend-session-secret-token')
        ->and($restoredContext->restaurantSlug())->toBe('trusted-restaurant')
        ->and($restoredContext->expiresAt()->equalTo($expiresAt))->toBeTrue();
});

it('keeps the backend session token out of model-facing structures', function () {
    $context = backendSessionContext(CarbonImmutable::now()->addHour());

    $handle = app(SessionContextHandle::class)->issue($context);

    expect($handle)->not->toContain('backend-session-secret-token')
        ->and(json_encode($context, JSON_THROW_ON_ERROR))->toBe('{}');
});

it('rejects a tampered context handle without leaking its contents', function () {
    $handle = app(SessionContextHandle::class)->issue(
        backendSessionContext(CarbonImmutable::now()->addHour()),
    );
    $tamperedHandle = substr_replace($handle, $handle[-1] === 'A' ? 'B' : 'A', -1);

    try {
        app(SessionContextHandle::class)->restore($tamperedHandle);
    } catch (InvalidSessionContextException $exception) {
        expect($exception->getMessage())->toBe('Контекст заказа недействителен или истёк. Создайте новый контекст заказа.')
            ->not->toContain($tamperedHandle)
            ->not->toContain('backend-session-secret-token');

        return;
    }

    $this->fail('A tampered session context handle was accepted.');
});

it('rejects a malformed context handle safely', function () {
    expect(fn () => app(SessionContextHandle::class)->restore('not-an-encrypted-handle'))
        ->toThrow(
            InvalidSessionContextException::class,
            'Контекст заказа недействителен или истёк. Создайте новый контекст заказа.',
        );
});

it('rejects authenticated payloads with an invalid shape', function () {
    $handle = encryptedSessionContextPayload([
        'version' => 1,
        'backend_session_id' => 'backend-session-id',
        'backend_session_token' => 'backend-session-secret-token',
        'restaurant_slug' => 'trusted-restaurant',
        'expires_at' => CarbonImmutable::now()->addHour()->format(DateTimeInterface::ATOM),
        'unexpected' => 'value',
    ]);

    expect(fn () => app(SessionContextHandle::class)->restore($handle))
        ->toThrow(InvalidSessionContextException::class);
});

it('rejects authenticated payloads with an invalid expiration', function () {
    $handle = encryptedSessionContextPayload([
        'version' => 1,
        'backend_session_id' => 'backend-session-id',
        'backend_session_token' => 'backend-session-secret-token',
        'restaurant_slug' => 'trusted-restaurant',
        'expires_at' => 'not-a-timestamp',
    ]);

    expect(fn () => app(SessionContextHandle::class)->restore($handle))
        ->toThrow(InvalidSessionContextException::class);
});

it('rejects an expired context handle', function () {
    CarbonImmutable::setTestNow('2026-08-12T12:00:00+00:00');
    $handle = app(SessionContextHandle::class)->issue(
        backendSessionContext(CarbonImmutable::now()),
    );

    expect(fn () => app(SessionContextHandle::class)->restore($handle))
        ->toThrow(
            InvalidSessionContextException::class,
            'Контекст заказа недействителен или истёк. Создайте новый контекст заказа.',
        );
});

arch('backend session context does not depend on persistence')
    ->expect([
        BackendSessionContext::class,
        SessionContextHandle::class,
    ])
    ->not->toUse([
        'Illuminate\\Cache',
        'Illuminate\\Contracts\\Cache',
        'Illuminate\\Contracts\\Session',
        'Illuminate\\Database',
        'Illuminate\\Redis',
        'Illuminate\\Session',
        'Illuminate\\Support\\Facades\\Cache',
        'Illuminate\\Support\\Facades\\DB',
        'Illuminate\\Support\\Facades\\Redis',
        'Redis',
    ]);

function backendSessionContext(CarbonImmutable $expiresAt): BackendSessionContext
{
    return new BackendSessionContext(
        backendSessionId: 'backend-session-id',
        backendSessionToken: 'backend-session-secret-token',
        restaurantSlug: 'trusted-restaurant',
        expiresAt: $expiresAt,
    );
}

/**
 * @param  array<string, mixed>  $payload
 */
function encryptedSessionContextPayload(array $payload): string
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return app(Encrypter::class)->encrypt($json, false);
}
