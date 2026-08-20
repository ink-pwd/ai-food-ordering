<?php

use App\DTO\OtpChallengeData;

test('otp challenge round trips through its persistence shape', function (): void {
    $payload = [
        'session_id' => '01KSESSION',
        'phone' => '+380931234567',
        'code_hash' => 'hashed-code',
        'attempts_remaining' => 3,
        'expires_at' => '2026-08-20T10:05:00+00:00',
        'resend_available_at' => '2026-08-20T10:01:00+00:00',
    ];

    expect(OtpChallengeData::fromArray($payload)->toArray())->toBe($payload);
});

test('otp challenge creates a new value when attempts change', function (): void {
    $challenge = new OtpChallengeData(
        sessionId: '01KSESSION',
        phone: '+380931234567',
        codeHash: 'hashed-code',
        attemptsRemaining: 3,
        expiresAt: '2026-08-20T10:05:00+00:00',
        resendAvailableAt: '2026-08-20T10:01:00+00:00',
    );

    $updated = $challenge->withAttemptsRemaining(2);

    expect($challenge->attemptsRemaining)->toBe(3)
        ->and($updated->attemptsRemaining)->toBe(2)
        ->and($updated->sessionId)->toBe($challenge->sessionId)
        ->and($updated->phone)->toBe($challenge->phone)
        ->and($updated->codeHash)->toBe($challenge->codeHash)
        ->and($updated->expiresAt)->toBe($challenge->expiresAt)
        ->and($updated->resendAvailableAt)->toBe($challenge->resendAvailableAt);
});
