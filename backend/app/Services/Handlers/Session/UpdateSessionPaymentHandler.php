<?php

namespace App\Services\Handlers\Session;

use App\Enums\PaymentType;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Repositories\SessionRepository;
use App\Services\Support\PaymentSelection;
use App\Services\Support\SessionSelection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpdateSessionPaymentHandler
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly RestaurantRepository $restaurants,
        private readonly CartRepository $carts,
    ) {}

    public function handle(
        array $session,
        string $plainToken,
        PaymentType $paymentType,
    ): array {
        $restaurant = $this->restaurants->findActiveById(
            SessionSelection::restaurantId($session),
        );

        if ($restaurant === null) {
            throw new NotFoundHttpException('Restaurant not found.');
        }

        PaymentSelection::assertSupported($restaurant, $paymentType);

        if ($this->carts->hasNonActiveCartForSession(
            $restaurant,
            $session['id'],
        )) {
            throw new ConflictHttpException(
                'Payment method cannot be changed after checkout.'
            );
        }

        $updatedSession = $this->sessions->updateMetadata(
            $plainToken,
            [
                'payment_type' => $paymentType->value,
            ],
        );

        if ($updatedSession === null) {
            throw new NotFoundHttpException('Session not found.');
        }

        return $updatedSession;
    }
}
