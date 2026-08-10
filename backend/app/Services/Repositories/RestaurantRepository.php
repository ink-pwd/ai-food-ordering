<?php

namespace App\Services\Repositories;

use App\Models\Restaurant;

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
                'name',
                'slug',
                'currency',
                'locale',
                'timezone',
            ])
            ->whereKey($id)
            ->where('is_active', true)
            ->first();
    }
}
