<?php

namespace App\Services\Handlers\Synchronization;

use App\Models\City;
use App\Models\Restaurant;
use App\Services\Repositories\RestaurantAddressRepository;
use App\Services\Repositories\RestaurantRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

readonly class SynchronizeDotsCompanyHandler
{
    public function __construct(
        private RestaurantRepository $restaurants,
        private RestaurantAddressRepository $addresses,
        private ValidateDotsTopologyPayloadHandler $validator,
    ) {
    }

    /**
     * @param  array<string, mixed>  $cityData
     * @param  array<string, mixed>  $companyData
     * @return array{
     *     restaurant_state: 'created'|'updated'|'unchanged',
     *     addresses: array{
     *         created: int,
     *         updated: int,
     *         unchanged: int,
     *         deactivated: int
     *     }
     * }
     */
    public function handle(
        City $city,
        array $cityData,
        array $companyData,
    ): array {
        // Restaurant and address synchronization must commit as one company snapshot.
        return DB::transaction(
            fn (): array => $this->synchronizeWithinTransaction(
                $city,
                $cityData,
                $companyData,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $cityData
     * @param  array<string, mixed>  $companyData
     * @return array{
     *     restaurant_state: 'created'|'updated'|'unchanged',
     *     addresses: array{
     *         created: int,
     *         updated: int,
     *         unchanged: int,
     *         deactivated: int
     *     }
     * }
     */
    private function synchronizeWithinTransaction(
        City $city,
        array $cityData,
        array $companyData,
    ): array {
        /** @var string $externalCompanyId */
        $externalCompanyId = $companyData['id'];

        $restaurantPersistence =
            $this->restaurants->upsertFromDots(
                $city,
                $externalCompanyId,
                $this->restaurantAttributes(
                    $cityData,
                    $companyData,
                ),
            );

        $restaurant =
            $restaurantPersistence['restaurant'];

        $addressItems =
            $companyData['addresses'] ?? [];

        /** @var array<int, array<string, mixed>> $addressItems */
        $this->validator->handle(
            ValidateDotsTopologyPayloadHandler::ADDRESSES,
            $addressItems,
        );

        return [
            'restaurant_state' => $restaurantPersistence['state'],

            'addresses' => $this->synchronizeAddresses(
                $restaurant,
                $addressItems,
            ),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $addressItems
     * @return array{
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     deactivated: int
     * }
     */
    private function synchronizeAddresses(
        Restaurant $restaurant,
        array $addressItems,
    ): array {
        $result = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'deactivated' => 0,
        ];

        /** @var array<int, string> $presentAddressIds */
        $presentAddressIds = [];

        foreach ($addressItems as $addressItem) {
            if (
                ! $this->isActiveDotsEntity(
                    $addressItem + ['status' => 1],
                )
            ) {
                continue;
            }

            /** @var string $externalAddressId */
            $externalAddressId =
                $addressItem['id'];

            $addressPersistence =
                $this->addresses->upsertForRestaurant(
                    $restaurant,
                    $externalAddressId,
                    $this->addressAttributes(
                        $addressItem,
                    ),
                );

            $presentAddressIds[] =
                $externalAddressId;

            $result[
            $addressPersistence['state']
            ]++;
        }

        $result['deactivated'] =
            $this->addresses
                ->deactivateMissingForRestaurant(
                    $restaurant,
                    $presentAddressIds,
                );

        return $result;
    }

    /**
     * @param  array<string, mixed>  $city
     * @param  array<string, mixed>  $company
     * @return array<string, mixed>
     */
    private function restaurantAttributes(
        array $city,
        array $company,
    ): array {
        /** @var string $currency */
        $currency = Arr::get(
            $city,
            'currency.token',
            'UAH',
        );

        /** @var string $timezone */
        $timezone =
            $city['timezone'] ?? 'Europe/Kyiv';

        /** @var array<int, array{type: int}> $paymentTypes */
        $paymentTypes =
            $company['availablePaymentTypes']
            ?? [];

        return [
            'name' => $company['name'],
            'slug' => $company['url'],
            'currency' => strtoupper($currency),
            'locale' => 'uk-UA',
            'timezone' => $timezone,
            'is_active' => $this->isActiveDotsEntity(
                $company,
            ),
            'image_url' => $company['image'] ?? null,
            'available_payment_types' => $this->availablePaymentTypes(
                $paymentTypes,
            ),
            'available_delivery_types' => $company[
                'availableDeliveryTypes'
                ] ?? [],
            'schedule' => $company['schedule'] ?? null,
            'delivery_time_text' => $company[
                'deliveryTimeText'
                ] ?? null,
            'delivery_price_text' => $company[
                'deliveryPriceText'
                ] ?? null,
        ];
    }

    /**
     * @param  array<int, array{type: int}>  $paymentTypes
     * @return list<int>
     */
    private function availablePaymentTypes(
        array $paymentTypes,
    ): array {
        return array_values(
            array_map(
                static fn (
                    array $paymentType,
                ): int => (int) $paymentType['type'],
                $paymentTypes,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    private function addressAttributes(
        array $address,
    ): array {
        return [
            'title' => $address['title'],
            'latitude' => Arr::get(
                $address,
                'location.latitude',
            ),
            'longitude' => Arr::get(
                $address,
                'location.longitude',
            ),
            'polygon' => $address['polygon'] ?? null,
            'is_active' => $this->isActiveDotsEntity(
                $address + ['status' => 1],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private function isActiveDotsEntity(
        array $entity,
    ): bool {
        /** @var int|string $status */
        $status = $entity['status'] ?? 0;

        return (int) $status === 1;
    }
}
