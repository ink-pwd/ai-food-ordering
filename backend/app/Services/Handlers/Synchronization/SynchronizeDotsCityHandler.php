<?php

namespace App\Services\Handlers\Synchronization;

use App\Models\City;
use App\Services\Repositories\CityRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

readonly class SynchronizeDotsCityHandler
{
    public function __construct(
        private CityRepository $cities,
    ) {
    }

    /**
     * @param  array<string, mixed>  $cityData
     * @return array{city: City, state: 'created'|'updated'|'unchanged'}
     */
    public function handle(array $cityData): array
    {
        // The repository resolves the existing row before create/update; keep that synchronization unit transactional.
        return DB::transaction(
            fn (): array => $this->synchronizeWithinTransaction(
                $cityData,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $cityData
     * @return array{city: City, state: 'created'|'updated'|'unchanged'}
     */
    private function synchronizeWithinTransaction(
        array $cityData,
    ): array {
        /** @var string $externalCityId */
        $externalCityId = $cityData['id'];

        return $this->cities->upsertFromDots(
            $externalCityId,
            $this->attributes($cityData),
        );
    }

    /**
     * @param  array<string, mixed>  $city
     * @return array<string, mixed>
     */
    private function attributes(array $city): array
    {
        /** @var array{token: string} $currency */
        $currency = $city['currency'];

        return [
            'name' => $city['name'],
            'slug' => $city['url'],
            'is_active' => $this->isActiveDotsEntity($city),
            'center_latitude' => Arr::get(
                $city,
                'centerCoordinates.latitude',
            ),
            'center_longitude' => Arr::get(
                $city,
                'centerCoordinates.longitude',
            ),
            'currency' => strtoupper(
                $currency['token'],
            ),
            'timezone' => $city['timezone'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private function isActiveDotsEntity(
        array $entity,
    ): bool {
        /** @var int|string $status */
        $status = $entity['status'] ?? 0;

        return (int) $status === 1;
    }
}
