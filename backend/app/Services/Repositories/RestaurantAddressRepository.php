<?php

namespace App\Services\Repositories;

use App\Models\Restaurant;
use App\Models\RestaurantAddress;

class RestaurantAddressRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{address: RestaurantAddress, state: 'created'|'updated'|'unchanged'}
     */
    public function upsertForRestaurant(Restaurant $restaurant, string $externalAddressId, array $attributes): array
    {
        $address = RestaurantAddress::query()
            ->where('external_address_id', $externalAddressId)
            ->first();

        if (! $address) {
            return [
                'address' => RestaurantAddress::query()->create(array_merge($attributes, [
                    'restaurant_id' => $restaurant->id,
                    'external_address_id' => $externalAddressId,
                ])),
                'state' => 'created',
            ];
        }

        $address->fill($attributes);

        if ($address->isDirty()) {
            $address->save();

            return [
                'address' => $address,
                'state' => 'updated',
            ];
        }

        return [
            'address' => $address,
            'state' => 'unchanged',
        ];
    }

    /**
     * @param  array<int, string>  $presentExternalAddressIds
     */
    public function deactivateMissingForRestaurant(Restaurant $restaurant, array $presentExternalAddressIds): int
    {
        $query = RestaurantAddress::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true);

        if ($presentExternalAddressIds !== []) {
            $query->whereNotIn('external_address_id', $presentExternalAddressIds);
        }

        return $query->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, RestaurantAddress>
     */
    public function activeForRestaurant(Restaurant $restaurant): \Illuminate\Database\Eloquent\Collection
    {
        return RestaurantAddress::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    public function findActiveForRestaurantById(Restaurant $restaurant, int $id): ?RestaurantAddress
    {
        return RestaurantAddress::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereKey($id)
            ->where('is_active', true)
            ->first();
    }

    public function deactivateAddressesForInactiveRestaurants(): int
    {
        return RestaurantAddress::query()
            ->where('is_active', true)
            ->whereHas('restaurant', function ($query): void {
                $query->where('is_active', false);
            })
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }
}
