<?php

namespace App\Mcp\Support;

use App\Integrations\OrderingBackend\OrderingBackendException;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;

final readonly class CatalogResponseMapper
{
    public function __construct(private ValidationFactory $validator) {}

    /**
     * @param  array<array-key, mixed>  $response
     * @return list<array{id: int, name: string, slug: string, sort_order: int}>
     */
    public function categories(array $response): array
    {
        $validation = $this->validator->make($response, [
            'data' => ['present', 'array', 'list'],
            'data.*' => ['required', 'array'],
            'data.*.id' => ['required', 'integer', 'min:1'],
            'data.*.name' => ['required', 'string'],
            'data.*.slug' => ['required', 'string'],
            'data.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        if ($validation->fails()) {
            throw OrderingBackendException::invalidResponse();
        }

        /** @var list<array{id: int, name: string, slug: string, sort_order: int}> $categories */
        $categories = array_map(
            static fn (array $category): array => [
                'id' => $category['id'],
                'name' => $category['name'],
                'slug' => $category['slug'],
                'sort_order' => $category['sort_order'],
            ],
            $response['data'],
        );

        return $categories;
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return list<array{id: int, name: string, description: string|null, price: string, promotion_price: string|null, currency: string, image_url: string|null, is_available: bool}>
     */
    public function products(array $response): array
    {
        $validation = $this->validator->make($response, [
            'data' => ['present', 'array', 'list'],
            'data.*' => ['required', 'array'],
            ...$this->productRules('data.*.'),
        ]);

        if ($validation->fails()) {
            throw OrderingBackendException::invalidResponse();
        }

        /** @var list<array{id: int, name: string, description: string|null, price: string, promotion_price: string|null, currency: string, image_url: string|null, is_available: bool}> $products */
        $products = array_map($this->product(...), $response['data']);

        return $products;
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return array{id: int, name: string, description: string|null, price: string, promotion_price: string|null, currency: string, image_url: string|null, is_available: bool}
     */
    public function productFromResponse(array $response): array
    {
        $validation = $this->validator->make($response, [
            'data' => ['required', 'array'],
            ...$this->productRules('data.'),
        ]);

        if ($validation->fails()) {
            throw OrderingBackendException::invalidResponse();
        }

        return $this->product($response['data']);
    }

    /**
     * @return array<string, list<string>>
     */
    private function productRules(string $prefix): array
    {
        return [
            $prefix.'id' => ['required', 'integer', 'min:1'],
            $prefix.'name' => ['required', 'string'],
            $prefix.'description' => ['present', 'nullable', 'string'],
            $prefix.'price' => ['required', 'string'],
            $prefix.'promotion_price' => ['present', 'nullable', 'string'],
            $prefix.'currency' => ['required', 'string', 'size:3'],
            $prefix.'image_url' => ['present', 'nullable', 'string'],
            $prefix.'is_available' => ['required', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{id: int, name: string, description: string|null, price: string, promotion_price: string|null, currency: string, image_url: string|null, is_available: bool}
     */
    private function product(array $product): array
    {
        return [
            'id' => $product['id'],
            'name' => $product['name'],
            'description' => $product['description'],
            'price' => $product['price'],
            'promotion_price' => $product['promotion_price'],
            'currency' => $product['currency'],
            'image_url' => $product['image_url'],
            'is_available' => $product['is_available'],
        ];
    }
}
