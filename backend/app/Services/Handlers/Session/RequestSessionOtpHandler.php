<?php

namespace App\Services\Handlers\Session;

use App\DTO\OtpChallengeData;
use App\DTO\SessionData;
use App\Services\Contracts\OtpSender;
use App\Services\Repositories\OtpChallengeRepository;
use App\Services\Support\SessionSelection;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

readonly class RequestSessionOtpHandler
{
    public function __construct(
        private OtpChallengeRepository $otps,
        private OtpSender $sender,
    ) {
    }

    /**
     * @return array{expires_in: int, resend_available_in: int, code: string}
     */
    public function handle(SessionData $session): array
    {
        $contact = SessionSelection::contact($session);

        if ($contact === null) {
            throw new ConflictHttpException('Contact information is required.');
        }

        if (($contact['phone_verified'] ?? null) === true) {
            throw new ConflictHttpException('Phone is already verified.');
        }

        if (($session->cityId) !== null || ($session->restaurantId) !== null) {
            throw new ConflictHttpException('OTP cannot be requested after city or restaurant selection.');
        }

        $existingChallenge = $this->otps->find($session->id);

        if ($existingChallenge !== null && $existingChallenge->phone === $contact['phone']) {
            $resendAvailableAt = now()->parse($existingChallenge->resendAvailableAt);

            if ($resendAvailableAt->isFuture()) {
                throw new ConflictHttpException('OTP resend is not available yet.');
            }
        }

        $code = $this->generateCode();
        $expiresAt = now()->addSeconds($this->ttlSeconds());
        $resendAvailableAt = now()->addSeconds($this->cooldownSeconds());

        $this->otps->put(
            $session->id,
            new OtpChallengeData(
                sessionId: $session->id,
                phone: $contact['phone'],
                codeHash: Hash::make($code),
                attemptsRemaining: $this->maxAttempts(),
                expiresAt: $expiresAt->toIso8601String(),
                resendAvailableAt: $resendAvailableAt->toIso8601String(),
            ),
        );

        $this->sender->send($contact['phone'], $code);

        return [
            'expires_in' => $this->ttlSeconds(),
            'resend_available_in' => $this->cooldownSeconds(),
            'code' => $code,
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
        /** @var int|string $codeLength */
        $codeLength = config('services.internal.otp.code_length');

        return max(4, min(10, (int) $codeLength));
    }

    private function ttlSeconds(): int
    {
        /** @var int|string $ttlSeconds */
        $ttlSeconds = config('services.internal.otp.ttl_seconds');

        return max(60, (int) $ttlSeconds);
    }

    private function cooldownSeconds(): int
    {
        /** @var int|string $cooldownSeconds */
        $cooldownSeconds = config('services.internal.otp.resend_cooldown_seconds');

        return max(1, (int) $cooldownSeconds);
    }

    private function maxAttempts(): int
    {
        /** @var int|string $maxAttempts */
        $maxAttempts = config('services.internal.otp.max_attempts');

        return max(1, (int) $maxAttempts);
    }
}
