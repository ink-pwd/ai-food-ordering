<?php

namespace App\Services\Handlers\Session;

use App\DTO\SessionData;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\OtpChallengeRepository;
use App\Services\Repositories\SessionRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class ExitSessionHandler
{
    public function __construct(
        private CartRepository $carts,
        private SessionRepository $sessions,
        private OtpChallengeRepository $otps,
    ) {
    }

    public function handle(
        string $plainToken,
        SessionData $session,
    ): SessionData {
        $restaurantId = $session->restaurantId;

        if (is_int($restaurantId) && $restaurantId > 0) {
            $this->carts->abandonActiveForSession(
                $restaurantId,
                $session->id,
            );
        }

        $this->otps->forget($session->id);

        $closedSession = $this->sessions->close($plainToken);

        if ($closedSession === null) {
            throw new NotFoundHttpException;
        }

        return SessionData::fromArray($closedSession);
    }
}
