<?php

namespace App\Services\Handlers\Session;

use App\Services\Contracts\OtpSender;
use App\Services\Repositories\OtpChallengeRepository;
use App\Services\Support\SessionSelection;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RequestSessionOtpHandler
{
    public function __construct(
        private readonly OtpChallengeRepository $otps,
        private readonly OtpSender $sender,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     * @return array{expires_in: int, resend_available_in: int}
     */
    public function handle(array $session): array
    {
        $contact = SessionSelection::contact($session);

        if ($contact === null) {
            throw new ConflictHttpException('Contact information is required.');
        }

        if (($contact['phone_verified'] ?? null) === true) {
            throw new ConflictHttpException('Phone is already verified.');
        }

        if (($session['city_id'] ?? null) !== null || ($session['restaurant_id'] ?? null) !== null) {
            throw new ConflictHttpException('OTP cannot be requested after city or restaurant selection.');
        }

        $existingChallenge = $this->otps->find($session['id']);

        if ($existingChallenge !== null && ($existingChallenge['phone'] ?? null) === $contact['phone']) {
            $resendAvailableAt = now()->parse($existingChallenge['resend_available_at']);

            if ($resendAvailableAt->isFuture()) {
                throw new ConflictHttpException('OTP resend is not available yet.');
            }
        }

        $code = $this->generateCode();
        $expiresAt = now()->addSeconds($this->ttlSeconds());
        $resendAvailableAt = now()->addSeconds($this->cooldownSeconds());

        $this->otps->put($session['id'], [
            'session_id' => $session['id'],
            'phone' => $contact['phone'],
            'code_hash' => Hash::make($code),
            'attempts_remaining' => $this->maxAttempts(),
            'expires_at' => $expiresAt->toIso8601String(),
            'resend_available_at' => $resendAvailableAt->toIso8601String(),
        ]);

        $this->sender->send($contact['phone'], $code);

        return [
            'expires_in' => $this->ttlSeconds(),
            'resend_available_in' => $this->cooldownSeconds(),
        ];
    }

    private function generateCode(): string
    {
        $length = $this->codeLength();
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function codeLength(): int
    {
        return max(4, min(10, (int) config('services.internal.otp.code_length')));
    }

    private function ttlSeconds(): int
    {
        return max(60, (int) config('services.internal.otp.ttl_seconds'));
    }

    private function cooldownSeconds(): int
    {
        return max(1, (int) config('services.internal.otp.resend_cooldown_seconds'));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('services.internal.otp.max_attempts'));
    }
}
