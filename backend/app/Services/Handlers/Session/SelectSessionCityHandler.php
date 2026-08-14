<?php

namespace App\Services\Handlers\Session;

use App\Models\City;
use App\Services\Repositories\CityRepository;
use App\Services\Repositories\SessionRepository;
use App\Services\Support\SessionSelection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SelectSessionCityHandler
{
    public function __construct(
        private readonly CityRepository $cities,
        private readonly SessionRepository $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     * @return array{session: array<string, mixed>, city: City}
     */
    public function handle(string $plainToken, array $session, int $cityId): array
    {
        SessionSelection::assertPhoneVerified($session);

        if (($session['city_id'] ?? null) !== null || ($session['restaurant_id'] ?? null) !== null) {
            throw new ConflictHttpException('City has already been selected.');
        }

        $city = $this->cities->findActiveById($cityId);

        if ($city === null) {
            throw new NotFoundHttpException('City not found.');
        }

        $updatedSession = $this->sessions->selectCity($plainToken, $city->id);

        if ($updatedSession === null) {
            throw new NotFoundHttpException;
        }

        return [
            'session' => $updatedSession,
            'city' => $city,
        ];
    }
}
