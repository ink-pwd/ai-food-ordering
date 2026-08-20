<?php

namespace App\Services\Handlers\Synchronization;

use App\DTO\ProductSynchronizationResultData;
use App\Models\Restaurant;
use App\Services\Repositories\ProductRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;

readonly class ProductSynchronizationHandler
{
    public function __construct(
        private ProductRepository $products,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     * @throws \Throwable
     */
    public function sync(
        Restaurant $restaurant,
        array $categories,
    ): ProductSynchronizationResultData {
        $this->validate($categories);

        $categoryIdsByExternalId =
            $this->resolveCategories(
                $restaurant,
                $categories,
            );

        // Product writes and category-relation changes must commit or roll back together.
        return DB::transaction(
            fn (): ProductSynchronizationResultData => $this->syncWithinTransaction(
                $restaurant,
                $categories,
                $categoryIdsByExternalId,
            ),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<string, int>  $categoryIdsByExternalId
     */
    private function syncWithinTransaction(
        Restaurant $restaurant,
        array $categories,
        array $categoryIdsByExternalId,
    ): ProductSynchronizationResultData {
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $relationsAttached = 0;
        $relationsDetached = 0;

        foreach ($categories as $categoryData) {
            /** @var string $categoryExternalId */
            $categoryExternalId = $categoryData['id'];
            $categoryId =
                $categoryIdsByExternalId[
                $categoryExternalId
                ];

            $categoryResult =
                $this->syncCategoryProducts(
                    $restaurant,
                    $categoryData,
                    $categoryId,
                );

            $created +=
                $categoryResult->created;

            $updated +=
                $categoryResult->updated;

            $unchanged +=
                $categoryResult->unchanged;

            $relationsAttached +=
                $categoryResult->relationsAttached;

            $relationsDetached +=
                $categoryResult->relationsDetached;
        }

        return new ProductSynchronizationResultData(
            created: $created,
            updated: $updated,
            unchanged: $unchanged,
            relationsAttached: $relationsAttached,
            relationsDetached: $relationsDetached,
        );
    }

    /**
     * @param  array<string, mixed>  $categoryData
     */
    private function syncCategoryProducts(
        Restaurant $restaurant,
        array $categoryData,
        int $categoryId,
    ): ProductSynchronizationResultData {
        $states = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
        ];

        $relationsAttached = 0;
        $relationsDetached = 0;

        /** @var array<int, array<string, mixed>> $items */
        $items = $categoryData['items'];

        foreach (
            $items
            as $sortOrder => $productData
        ) {
            $attributes =
                $this->productAttributes(
                    $restaurant,
                    $productData,
                    $sortOrder,
                );

            /** @var string $productExternalId */
            $productExternalId = $productData['id'];

            $persistence =
                $this->products
                    ->upsertForRestaurant(
                        $restaurant,
                        $productExternalId,
                        $attributes,
                    );

            $states[
            $persistence['state']
            ]++;

            $relationResult =
                $this->products
                    ->syncCategoryRelation(
                        $persistence['product'],
                        $categoryId,
                        $restaurant,
                    );

            $relationsAttached +=
                $relationResult['attached'];

            $relationsDetached +=
                $relationResult['detached'];
        }

        return new ProductSynchronizationResultData(
            created: $states['created'],
            updated: $states['updated'],
            unchanged: $states['unchanged'],
            relationsAttached: $relationsAttached,
            relationsDetached: $relationsDetached,
        );
    }

    /**
     * @param  array<string, mixed>  $productData
     * @return array<string, mixed>
     */
    private function productAttributes(
        Restaurant $restaurant,
        array $productData,
        int $sortOrder,
    ): array {
        return [
            'name' => $productData['name'],

            'description' => $productData[
                'description'
                ] ?? null,

            'price' => $productData['price'],

            'promotion_price' => $productData[
                'promotionPrice'
                ] ?? null,

            'currency' => $restaurant->currency,

            'image_url' => $productData[
                'image'
                ] ?? null,

            'is_available' => $productData[
                'isAvailableToOrder'
                ],

            'sort_order' => $sortOrder,

            'original_payload' => $productData,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<string, int>
     */
    private function resolveCategories(
        Restaurant $restaurant,
        array $categories,
    ): array {
        /** @var array<int, string> $externalIds */
        $externalIds =
            collect($categories)
                ->pluck('id')
                ->all();

        $categoryIdsByExternalId =
            $this->products
                ->categoryIdsByExternalId(
                    $restaurant,
                    $externalIds,
                );

        $missingExternalIds =
            array_values(
                array_diff(
                    $externalIds,
                    array_keys(
                        $categoryIdsByExternalId,
                    ),
                ),
            );

        if ($missingExternalIds !== []) {
            throw ValidationException::withMessages([
                'categories' => [
                    'Missing local categories: '
                    .implode(
                        ', ',
                        $missingExternalIds,
                    ),
                ],
            ]);
        }

        return $categoryIdsByExternalId;
    }

    /**
     * @param  array<string, mixed>  $categoryData
     * @param  array<int, string>  $productIds
     */
    private function validateCategoryProducts(
        LaravelValidator $validator,
        array $categoryData,
        int $categoryIndex,
        array &$productIds,
    ): void {
        /** @var array<int, array<string, mixed>> $items */
        $items = Arr::get(
            $categoryData,
            'items',
            [],
        );

        foreach ($items as $productIndex => $productData) {
            if (
                (
                    $productData[
                    'companyCategoryId'
                    ] ?? null
                )
                !== (
                    $categoryData['id']
                    ?? null
                )
            ) {
                $validator
                    ->errors()
                    ->add(
                        "categories.{$categoryIndex}.items.{$productIndex}.companyCategoryId",
                        'The product company category ID must match its parent category ID.',
                    );
            }

            if (
                ! isset(
                    $productData['id'],
                )
            ) {
                continue;
            }

            /** @var string $productId */
            $productId = $productData['id'];

            if (
                in_array(
                    $productId,
                    $productIds,
                    true,
                )
            ) {
                $validator
                    ->errors()
                    ->add(
                        "categories.{$categoryIndex}.items.{$productIndex}.id",
                        'The product ID must be distinct across the payload.',
                    );
            }

            $productIds[] =
                $productId;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     */
    private function validate(
        array $categories,
    ): void {
        $validator = Validator::make(
            [
                'categories' => $categories,
            ],
            [
                'categories' => [
                    'array',
                    'list',
                ],

                'categories.*' => [
                    'required',
                    'array',
                ],

                'categories.*.id' => [
                    'required',
                    'uuid',
                    'distinct',
                ],

                'categories.*.items' => [
                    'required',
                    'array',
                    'list',
                ],

                'categories.*.items.*' => [
                    'required',
                    'array',
                ],

                'categories.*.items.*.id' => [
                    'required',
                    'uuid',
                ],

                'categories.*.items.*.companyCategoryId' => [
                    'required',
                    'uuid',
                ],

                'categories.*.items.*.name' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'categories.*.items.*.price' => [
                    'required',
                    'numeric',
                    'min:0',
                    'max:9999999999.99',
                    'decimal:0,2',
                ],

                'categories.*.items.*.promotionPrice' => [
                    'nullable',
                    'numeric',
                    'min:0',
                    'max:9999999999.99',
                    'decimal:0,2',
                ],

                'categories.*.items.*.isAvailableToOrder' => [
                    'required',
                    'boolean',
                ],

                'categories.*.items.*.description' => [
                    'nullable',
                    'string',
                ],

                'categories.*.items.*.image' => [
                    'nullable',
                    'string',
                ],
            ],
        );

        $validator->after(
            function (
                LaravelValidator $validator,
            ) use ($categories): void {
                $productIds = [];

                foreach (
                    $categories
                    as $categoryIndex => $categoryData
                ) {
                    $this->validateCategoryProducts(
                        $validator,
                        $categoryData,
                        $categoryIndex,
                        $productIds,
                    );
                }
            },
        );

        $validator->validate();
    }
}
