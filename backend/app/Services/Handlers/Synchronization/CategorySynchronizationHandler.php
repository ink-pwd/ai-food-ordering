<?php

namespace App\Services\Handlers\Synchronization;

use App\Models\Restaurant;
use App\Services\Repositories\CategoryRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CategorySynchronizationHandler
{
    public function __construct(
        private readonly CategoryRepository $categories,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function sync(Restaurant $restaurant, array $categories): array
    {
        $this->validate($categories);

        return DB::transaction(function () use ($restaurant, $categories): array {
            $result = [
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
            ];

            foreach ($categories as $sortOrder => $categoryData) {
                $name = trim($categoryData['name']);

                $attributes = [
                    'name' => $name !== ''
                        ? $name
                        : $categoryData['url'],
                    'slug' => $categoryData['url'],
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                ];

                $persistence = $this->categories->upsertForRestaurant(
                    $restaurant,
                    $categoryData['id'],
                    $attributes,
                );

                $result[$persistence['state']]++;
            }

            return $result;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     */
    private function validate(array $categories): void
    {
        Validator::make(['categories' => $categories], [
            'categories' => ['array', 'list'],
            'categories.*' => ['required', 'array'],
            'categories.*.id' => ['required', 'uuid', 'distinct'],
            'categories.*.name' => ['present', 'string', 'max:255'],
            'categories.*.url' => ['required', 'string', 'max:255'],
        ])->validate();
    }
}
