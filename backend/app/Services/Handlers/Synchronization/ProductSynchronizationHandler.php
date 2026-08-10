<?php

namespace App\Services\Handlers\Synchronization;

use App\Models\Restaurant;
use App\Services\Repositories\ProductRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductSynchronizationHandler
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array{created: int, updated: int, unchanged: int, relations_attached: int, relations_detached: int}
     */
    public function sync(Restaurant $restaurant, array $categories): array
    {
        $this->validate($categories);

        $categoryIdsByExternalId = $this->resolveCategories($restaurant, $categories);

        return DB::transaction(function () use ($restaurant, $categories, $categoryIdsByExternalId): array {
            $result = [
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'relations_attached' => 0,
                'relations_detached' => 0,
            ];

            foreach ($categories as $categoryData) {
                $categoryId = $categoryIdsByExternalId[$categoryData['id']];

                foreach ($categoryData['items'] as $sortOrder => $productData) {
                    $attributes = $this->productAttributes($restaurant, $productData, $sortOrder);

                    $persistence = $this->products->upsertForRestaurant(
                        $restaurant,
                        $productData['id'],
                        $attributes,
                    );

                    $result[$persistence['state']]++;

                    $relationResult = $this->products->syncCategoryRelation(
                        $persistence['product'],
                        $categoryId,
                        $restaurant,
                    );

                    $result['relations_attached'] += $relationResult['attached'];
                    $result['relations_detached'] += $relationResult['detached'];
                }
            }

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $productData
     * @return array<string, mixed>
     */
    private function productAttributes(Restaurant $restaurant, array $productData, int $sortOrder): array
    {
        return [
            'name' => $productData['name'],
            'description' => $productData['description'] ?? null,
            'price' => $productData['price'],
            'promotion_price' => $productData['promotionPrice'] ?? null,
            'currency' => $restaurant->currency,
            'image_url' => $productData['image'] ?? null,
            'is_available' => $productData['isAvailableToOrder'],
            'sort_order' => $sortOrder,
            'original_payload' => $productData,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<string, int>
     */
    private function resolveCategories(Restaurant $restaurant, array $categories): array
    {
        $externalIds = collect($categories)->pluck('id')->all();

        $categoryIdsByExternalId = $this->products->categoryIdsByExternalId($restaurant, $externalIds);

        $missingExternalIds = array_values(array_diff($externalIds, array_keys($categoryIdsByExternalId)));

        if ($missingExternalIds !== []) {
            throw ValidationException::withMessages([
                'categories' => ['Missing local categories: '.implode(', ', $missingExternalIds)],
            ]);
        }

        return $categoryIdsByExternalId;
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     */
    private function validate(array $categories): void
    {
        $validator = Validator::make(['categories' => $categories], [
            'categories' => ['array', 'list'],
            'categories.*' => ['required', 'array'],
            'categories.*.id' => ['required', 'uuid', 'distinct'],
            'categories.*.items' => ['required', 'array', 'list'],
            'categories.*.items.*' => ['required', 'array'],
            'categories.*.items.*.id' => ['required', 'uuid'],
            'categories.*.items.*.companyCategoryId' => ['required', 'uuid'],
            'categories.*.items.*.name' => ['required', 'string', 'max:255'],
            'categories.*.items.*.price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'categories.*.items.*.promotionPrice' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'categories.*.items.*.isAvailableToOrder' => ['required', 'boolean'],
            'categories.*.items.*.description' => ['nullable', 'string'],
            'categories.*.items.*.image' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($categories): void {
            $productIds = [];

            foreach ($categories as $categoryIndex => $categoryData) {
                foreach (Arr::get($categoryData, 'items', []) as $productIndex => $productData) {
                    if (($productData['companyCategoryId'] ?? null) !== ($categoryData['id'] ?? null)) {
                        $validator->errors()->add(
                            "categories.{$categoryIndex}.items.{$productIndex}.companyCategoryId",
                            'The product company category ID must match its parent category ID.',
                        );
                    }

                    if (! isset($productData['id'])) {
                        continue;
                    }

                    if (in_array($productData['id'], $productIds, true)) {
                        $validator->errors()->add(
                            "categories.{$categoryIndex}.items.{$productIndex}.id",
                            'The product ID must be distinct across the payload.',
                        );
                    }

                    $productIds[] = $productData['id'];
                }
            }
        });

        $validator->validate();
    }
}
