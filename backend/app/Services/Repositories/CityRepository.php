<?php

namespace App\Services\Repositories;

use App\Models\City;
use Illuminate\Database\Eloquent\Collection;

class CityRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{city: City, state: 'created'|'updated'|'unchanged'}
     */
    public function upsertFromDots(string $externalCityId, array $attributes): array
    {
        $city = City::query()
            ->where('external_city_id', $externalCityId)
            ->first();

        if (! $city) {
            return [
                'city' => City::query()->create(array_merge($attributes, [
                    'external_city_id' => $externalCityId,
                ])),
                'state' => 'created',
            ];
        }

        $city->fill($attributes);

        if ($city->isDirty()) {
            $city->save();

            return [
                'city' => $city,
                'state' => 'updated',
            ];
        }

        return [
            'city' => $city,
            'state' => 'unchanged',
        ];
    }

    /**
     * @return Collection<int, City>
     */
    public function active(): Collection
    {
        return City::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    public function findActiveById(int $id): ?City
    {
        return City::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param  array<int, string>  $presentExternalCityIds
     */
    public function deactivateMissing(array $presentExternalCityIds): int
    {
        $query = City::query()->where('is_active', true);

        if ($presentExternalCityIds !== []) {
            $query->whereNotIn('external_city_id', $presentExternalCityIds);
        }

        return $query->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
    }
}
