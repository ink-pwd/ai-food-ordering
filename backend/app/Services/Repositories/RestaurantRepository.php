<?php

namespace App\Services\Repositories;

use App\Models\City;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Collection;

class RestaurantRepository
{
    public function findActiveBySlug(string $slug): ?Restaurant
    {
        return Restaurant::query()
            ->select(['id', 'name', 'slug', 'currency', 'locale', 'timezone'])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();
    }

    public function findActiveById(int $id): ?Restaurant
    {
        return Restaurant::query()
            ->select([
                'id',
                'external_company_id',
                'city_id',
                'name',
                'slug',
                'currency',
                'locale',
                'timezone',
                'available_delivery_types',
                'available_payment_types',
                'delivery_time_text',
                'delivery_price_text',
            ])
            ->whereKey($id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{restaurant: Restaurant, state: 'created'|'updated'|'unchanged'}
     */
    public function upsertFromDots(City $city, string $externalCompanyId, array $attributes): array
    {
        $restaurant = Restaurant::query()
            ->where('external_company_id', $externalCompanyId)
            ->first();

        $attributes = array_merge($attributes, ['city_id' => $city->id]);

        if (! $restaurant) {
            return [
                'restaurant' => Restaurant::query()->create(array_merge($attributes, [
                    'external_company_id' => $externalCompanyId,
                ])),
                'state' => 'created',
            ];
        }

        $restaurant->fill($attributes);

        if ($restaurant->isDirty()) {
            $restaurant->save();

            return [
                'restaurant' => $restaurant,
                'state' => 'updated',
            ];
        }

        return [
            'restaurant' => $restaurant,
            'state' => 'unchanged',
        ];
    }

    /**
     * @return Collection<int, Restaurant>
     */
    public function activeSynchronized(): Collection
    {
        return Restaurant::query()
            ->whereNotNull('city_id')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Restaurant>
     */
    public function activeForCity(City $city): Collection
    {
        return Restaurant::query()
            ->where('city_id', $city->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function findActiveForCityById(City $city, int $id): ?Restaurant
    {
        return Restaurant::query()
            ->where('city_id', $city->id)
            ->whereKey($id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param  array<int, string>  $presentExternalCompanyIds
     */
    public function deactivateMissingForCity(City $city, array $presentExternalCompanyIds): int
    {
        $query = Restaurant::query()
            ->where('city_id', $city->id)
            ->where('is_active', true);

        if ($presentExternalCompanyIds !== []) {
            $query->whereNotIn('external_company_id', $presentExternalCompanyIds);
        }

        return $query->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
    }

    public function deactivateRestaurantsForInactiveCities(): int
    {
        return Restaurant::query()
            ->where('is_active', true)
            ->whereHas('city', function ($query): void {
                $query->where('is_active', false);
            })
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }
}
