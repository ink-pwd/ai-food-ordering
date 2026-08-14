<?php

namespace App\Services\Handlers\Session;

use App\Services\Repositories\OtpChallengeRepository;
use App\Services\Repositories\SessionRepository;
use App\Services\Support\SessionSelection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class VerifySessionOtpHandler
{
    public function __construct(
        private readonly OtpChallengeRepository $otps,
        private readonly SessionRepository $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    public function handle(string $plainToken, array $session, string $code): array
    {
        $contact = SessionSelection::contact($session);

        if ($contact === null) {
            throw new ConflictHttpException('Contact information is required.');
        }

        if (($contact['phone_verified'] ?? null) === true) {
            throw new ConflictHttpException('Phone is already verified.');
        }

        $challenge = $this->otps->find($session['id']);

        if ($challenge === null
            || $challenge['session_id'] !== $session['id']
            || $challenge['phone'] !== $contact['phone']
            || now()->parse($challenge['expires_at'])->isPast()
            || $challenge['attempts_remaining'] < 1
        ) {
            $this->otps->forget($session['id']);

            throw ValidationException::withMessages([
                'code' => ['The OTP code is invalid or expired.'],
            ]);
        }

        if (! Hash::check($code, $challenge['code_hash'])) {
            $challenge['attempts_remaining']--;

            if ($challenge['attempts_remaining'] < 1) {
                $this->otps->forget($session['id']);
            } else {
                $this->otps->update($session['id'], $challenge);
            }

            throw ValidationException::withMessages([
                'code' => ['The OTP code is invalid or expired.'],
            ]);
        }

        $this->otps->forget($session['id']);

        $updatedSession = $this->sessions->updateMetadata($plainToken, [
            'contact' => array_merge($contact, ['phone_verified' => true]),
        ]);

        if ($updatedSession === null) {
            throw new NotFoundHttpException;
        }

        return $updatedSession;
    }
}
