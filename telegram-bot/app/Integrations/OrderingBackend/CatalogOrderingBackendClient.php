<?php

namespace App\Integrations\OrderingBackend;

use App\DTO\OrderingBackend\ProductData;
use App\DTO\OrderingBackend\ProductSummaryData;
use Illuminate\Http\Client\Response;

final readonly class CatalogOrderingBackendClient
{
    public function __construct(
        private OrderingBackendTransport $transport,
    ) {
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function categories(
        string $restaurantSlug,
    ): array {
        $response = $this->transport->get(
            path: "api/restaurants/{$restaurantSlug}/categories",
            operation: 'list_categories',
            message: 'Unable to retrieve ordering backend categories.',
        );

        $categories = $this->transport->responseData(
            $response,
            'list_categories',
        );

        if (
            ! is_array($categories)
            || ! array_is_list($categories)
        ) {
            throw $this->transport->invalidResponse(
                $response,
                'list_categories',
            );
        }

        return array_map(
            function (mixed $category) use ($response): array {
                if (! $this->isValidCategory($category)) {
                    throw $this->transport->invalidResponse(
                        $response,
                        'list_categories',
                    );
                }

                /** @var array{id: int, name: string} $category */
                return [
                    'id' => $category['id'],
                    'name' => $category['name'],
                ];
            },
            $categories,
        );
    }

    /**
     * @return list<ProductSummaryData>
     */
    public function categoryProducts(
        string $restaurantSlug,
        int $categoryId,
    ): array {
        $response = $this->transport->get(
            path: "api/restaurants/{$restaurantSlug}/categories/{$categoryId}/products",
            operation: 'list_category_products',
            message: 'Unable to retrieve ordering backend category products.',
        );

        return $this->productsFromResponse(
            $response,
            'list_category_products',
        );
    }

    public function product(
        string $restaurantSlug,
        int $productId,
    ): ProductData {
        $response = $this->transport->get(
            path: "api/restaurants/{$restaurantSlug}/products/{$productId}",
            operation: 'get_product',
            message: 'Unable to retrieve an ordering backend product.',
        );

        $product = $this->transport->responseData(
            $response,
            'get_product',
        );

        if (! $this->isValidProduct($product, $productId)) {
            throw $this->transport->invalidResponse(
                $response,
                'get_product',
            );
        }

        /** @var array{id: int, name: string, description?: string|null, price: string, promotion_price?: string|null, currency: string, is_available: bool} $product */
        return new ProductData(
            id: $product['id'],
            name: $product['name'],
            description: $product['description'] ?? null,
            price: $product['price'],
            promotionPrice: $product['promotion_price'] ?? null,
            currency: $product['currency'],
            isAvailable: $product['is_available'],
        );
    }

    /**
     * @return list<ProductSummaryData>
     */
    public function searchProducts(
        string $restaurantSlug,
        string $query,
        int $limit = 10,
    ): array {
        $response = $this->transport->get(
            path: "api/restaurants/{$restaurantSlug}/products/search",
            operation: 'search_products',
            message: 'Unable to search ordering backend products.',
            query: [
                'q' => $query,
                'limit' => $limit,
            ],
        );

        return $this->productsFromResponse(
            $response,
            'search_products',
        );
    }

    private function isValidCategory(mixed $category): bool
    {
        return is_array($category)
            && $this->transport->isPositiveInteger(
                $category['id'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $category['name'] ?? null,
            );
    }

    private function isValidProduct(
        mixed $product,
        int $productId,
    ): bool {
        return is_array($product)
            && ($product['id'] ?? null) === $productId
            && $this->transport->isNonEmptyString(
                $product['name'] ?? null,
            )
            && $this->transport->isOptionalString(
                $product['description'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $product['price'] ?? null,
            )
            && $this->transport->isOptionalNonEmptyString(
                $product['promotion_price'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $product['currency'] ?? null,
            )
            && is_bool(
                $product['is_available'] ?? null,
            );
    }

    private function isValidProductSummary(
        mixed $product,
    ): bool {
        return is_array($product)
            && $this->transport->isPositiveInteger(
                $product['id'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $product['name'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $product['price'] ?? null,
            )
            && $this->transport->isOptionalNonEmptyString(
                $product['promotion_price'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $product['currency'] ?? null,
            );
    }

    /**
     * @return list<ProductSummaryData>
     */
    private function productsFromResponse(
        Response $response,
        string $operation,
    ): array {
        $products = $this->transport->responseData(
            $response,
            $operation,
        );

        if (
            ! is_array($products)
            || ! array_is_list($products)
        ) {
            throw $this->transport->invalidResponse(
                $response,
                $operation,
            );
        }

        return array_map(
            function (mixed $product) use (
                $response,
                $operation,
            ): ProductSummaryData {
                if (! $this->isValidProductSummary($product)) {
                    throw $this->transport->invalidResponse(
                        $response,
                        $operation,
                    );
                }

                /** @var array{id: int, name: string, price: string, promotion_price?: string|null, currency: string} $product */
                return new ProductSummaryData(
                    id: $product['id'],
                    name: $product['name'],
                    price: $product['price'],
                    promotionPrice: $product['promotion_price'] ?? null,
                    currency: $product['currency'],
                );
            },
            $products,
        );
    }
}
