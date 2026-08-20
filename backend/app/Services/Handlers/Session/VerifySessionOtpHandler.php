<?php

namespace App\Services\Handlers\Session;

use App\DTO\OtpChallengeData;
use App\DTO\SessionData;
use App\Services\Repositories\OtpChallengeRepository;
use App\Services\Repositories\SessionRepository;
use App\Services\Support\SessionSelection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class VerifySessionOtpHandler
{
    public function __construct(
        private OtpChallengeRepository $otps,
        private SessionRepository $sessions,
    ) {
    }

    public function handle(
        string $plainToken,
        SessionData $session,
        string $code,
    ): SessionData {
        $contact = SessionSelection::contact($session);

        if ($contact === null) {
            throw new ConflictHttpException('Contact information is required.');
        }

        if (($contact['phone_verified'] ?? null) === true) {
            throw new ConflictHttpException('Phone is already verified.');
        }

        $challenge = $this->otps->find($session->id);

        if (
            $this->isInvalidChallenge(
                $challenge,
                $session->id,
                $contact['phone'],
            )
        ) {
            $this->otps->forget($session->id);

            throw ValidationException::withMessages([
                'code' => ['The OTP code is invalid or expired.'],
            ]);
        }

        /** @var OtpChallengeData $challenge */
        if (! Hash::check($code, $challenge->codeHash)) {
            $challenge = $challenge->withAttemptsRemaining(
                $challenge->attemptsRemaining - 1,
            );

            if ($challenge->attemptsRemaining < 1) {
                $this->otps->forget($session->id);
            } else {
                $this->otps->update($session->id, $challenge);
            }

            throw ValidationException::withMessages([
                'code' => ['The OTP code is invalid or expired.'],
            ]);
        }

        $this->otps->forget($session->id);

        $updatedSession = $this->sessions->updateMetadata($plainToken, [
            'contact' => array_merge($contact, ['phone_verified' => true]),
        ]);

        if ($updatedSession === null) {
            throw new NotFoundHttpException;
        }

        return SessionData::fromArray($updatedSession);
    }

    private function isInvalidChallenge(
        ?OtpChallengeData $challenge,
        string $sessionId,
        string $phone,
    ): bool {
        return $challenge === null
            || $challenge->sessionId !== $sessionId
            || $challenge->phone !== $phone
            || now()->parse(
                $challenge->expiresAt,
            )->isPast()
            || $challenge->attemptsRemaining < 1;
    }
}
