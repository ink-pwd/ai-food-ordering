<?php

namespace App\Services\Handlers\Session;

use App\Services\Repositories\CartRepository;
use App\Services\Repositories\OtpChallengeRepository;
use App\Services\Repositories\SessionRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ExitSessionHandler
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly SessionRepository $sessions,
        private readonly OtpChallengeRepository $otps,
    ) {}

    /** @param array<string, mixed> $session */
    public function handle(string $plainToken, array $session): array
    {
        $restaurantId = $session['restaurant_id'] ?? null;

        if (is_int($restaurantId) && $restaurantId > 0) {
            $this->carts->abandonActiveForSession(
                $restaurantId,
                $session['id'],
            );
        }

        $this->otps->forget($session['id']);

        $closedSession = $this->sessions->close($plainToken);

        if ($closedSession === null) {
            throw new NotFoundHttpException;
        }

        return $closedSession;
    }
}
