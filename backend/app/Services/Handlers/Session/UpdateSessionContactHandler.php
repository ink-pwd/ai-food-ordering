<?php

namespace App\Services\Handlers\Session;

use App\DTO\SessionData;
use App\Services\Repositories\OtpChallengeRepository;
use App\Services\Repositories\SessionRepository;

readonly class UpdateSessionContactHandler
{
    public function __construct(
        private SessionRepository $sessions,
        private OtpChallengeRepository $otps,
    ) {
    }

    /** @throws \JsonException */
    public function handle(
        string $plainToken,
        string $name,
        string $normalizedPhone,
    ): ?SessionData {
        $session = $this->sessions->findByToken($plainToken);

        if ($session !== null) {
            $this->otps->forget($session['id']);
        }

        $updatedSession = $this->sessions->updateMetadata(
            $plainToken,
            [
                'contact' => [
                    'name' => $name,
                    'phone' => $normalizedPhone,
                    'phone_verified' => false,
                ],
            ],
        );

        return $updatedSession === null
            ? null
            : SessionData::fromArray($updatedSession);
    }
}
