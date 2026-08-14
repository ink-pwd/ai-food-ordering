<?php

namespace App\Services\Handlers\Session;

use App\Services\Repositories\OtpChallengeRepository;
use App\Services\Repositories\SessionRepository;

class UpdateSessionContactHandler
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly OtpChallengeRepository $otps,
    ) {}

    /**
     * @return array{id: string, restaurant_id: int, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}|null
     */
    public function handle(string $plainToken, string $name, string $normalizedPhone): ?array
    {
        $session = $this->sessions->findByToken($plainToken);

        if ($session !== null) {
            $this->otps->forget($session['id']);
        }

        return $this->sessions->updateMetadata($plainToken, [
            'contact' => [
                'name' => $name,
                'phone' => $normalizedPhone,
                'phone_verified' => false,
            ],
        ]);
    }
}
