<?php

namespace App\Services\Handlers\Synchronization;

use App\Models\Restaurant;
use App\Services\Repositories\CategoryRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

readonly class CategorySynchronizationHandler
{
    public function __construct(
        private CategoryRepository $categories,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function sync(
        Restaurant $restaurant,
        array $categories,
    ): array {
        $this->validate($categories);

        // All category writes for this catalog snapshot must commit or roll back together.
        return DB::transaction(
            fn (): array => $this->syncWithinTransaction(
                $restaurant,
                $categories,
            ),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array{created: int, updated: int, unchanged: int}
     */
    private function syncWithinTransaction(
        Restaurant $restaurant,
        array $categories,
    ): array {
        $result = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
        ];

        foreach ($categories as $sortOrder => $categoryData) {
            /** @var string $categoryName */
            $categoryName = $categoryData['name'];
            /** @var string $categoryUrl */
            $categoryUrl = $categoryData['url'];
            /** @var string $externalId */
            $externalId = $categoryData['id'];

            $name = trim($categoryName);

            $attributes = [
                'name' => $name !== ''
                    ? $name
                    : $categoryUrl,
                'slug' => $categoryUrl,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ];

            $persistence = $this->categories->upsertForRestaurant(
                $restaurant,
                $externalId,
                $attributes,
            );

            $result[$persistence['state']]++;
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     */
    private function validate(array $categories): void
    {
        Validator::make(
            ['categories' => $categories],
            [
                'categories' => ['array', 'list'],
                'categories.*' => ['required', 'array'],
                'categories.*.id' => [
                    'required',
                    'uuid',
                    'distinct',
                ],
                'categories.*.name' => [
                    'present',
                    'string',
                    'max:255',
                ],
                'categories.*.url' => [
                    'required',
                    'string',
                    'max:255',
                ],
            ],
        )->validate();
    }
}
