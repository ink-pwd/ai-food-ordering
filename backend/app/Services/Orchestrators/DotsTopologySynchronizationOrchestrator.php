<?php

namespace App\Services\Orchestrators;

use App\Integrations\Dots\DiscoveryApi;
use App\Jobs\SyncRestaurantCatalog;
use App\Models\City;
use App\Services\Repositories\CityRepository;
use App\Services\Repositories\RestaurantAddressRepository;
use App\Services\Repositories\RestaurantRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DotsTopologySynchronizationOrchestrator
{
    public function __construct(
        private readonly DiscoveryApi $discoveryApi,
        private readonly CityRepository $cities,
        private readonly RestaurantRepository $restaurants,
        private readonly RestaurantAddressRepository $addresses,
    ) {}

    /**
     * @return array{cities: array<string, int>, restaurants: array<string, int>, addresses: array<string, int>, catalog_jobs: int}
     */
    public function sync(): array
    {
        $citiesResponse = $this->discoveryApi->refreshActiveCities();
        $cityItems = $this->completeList($citiesResponse, 'cities');
        $this->validateCities($cityItems);

        $result = [
            'cities' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0],
            'restaurants' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0],
            'addresses' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0],
            'catalog_jobs' => 0,
        ];
        $presentCityIds = [];

        foreach ($cityItems as $cityItem) {
            if (! $this->isActiveDotsEntity($cityItem)) {
                continue;
            }

            $cityDetails = $this->discoveryApi->getCity($cityItem['id']);
            $this->validateCityDetails($cityDetails);
            $cityData = array_replace_recursive($cityItem, $cityDetails);

            $city = DB::transaction(function () use ($cityData, &$result): City {
                $persistence = $this->cities->upsertFromDots(
                    $cityData['id'],
                    $this->cityAttributes($cityData),
                );

                $result['cities'][$persistence['state']]++;

                return $persistence['city'];
            });

            $presentCityIds[] = $cityData['id'];

            $companiesResponse = $this->discoveryApi->refreshCityCompanies($cityData['id']);
            $companyItems = $this->completeList($companiesResponse, 'companies');
            $this->validateCompanies($companyItems);

            $presentCompanyIds = [];

            foreach ($companyItems as $companyItem) {
                if (! $this->isActiveDotsEntity($companyItem)) {
                    continue;
                }

                $companyDetails = $this->discoveryApi->getCompany($companyItem['id']);
                $this->validateCompanyDetails($companyDetails);
                $companyData = array_replace_recursive($companyItem, $companyDetails);

                DB::transaction(function () use ($city, $cityData, $companyData, &$result): void {
                    $restaurantPersistence = $this->restaurants->upsertFromDots(
                        $city,
                        $companyData['id'],
                        $this->restaurantAttributes($cityData, $companyData),
                    );
                    $restaurant = $restaurantPersistence['restaurant'];

                    $result['restaurants'][$restaurantPersistence['state']]++;

                    $addressItems = $companyData['addresses'] ?? [];
                    $this->validateAddresses($addressItems);
                    $presentAddressIds = [];

                    foreach ($addressItems as $addressItem) {
                        if (! $this->isActiveDotsEntity($addressItem + ['status' => 1])) {
                            continue;
                        }

                        $addressPersistence = $this->addresses->upsertForRestaurant(
                            $restaurant,
                            $addressItem['id'],
                            $this->addressAttributes($addressItem),
                        );

                        $presentAddressIds[] = $addressItem['id'];
                        $result['addresses'][$addressPersistence['state']]++;
                    }

                    $result['addresses']['deactivated'] += $this->addresses->deactivateMissingForRestaurant(
                        $restaurant,
                        $presentAddressIds,
                    );
                });

                $presentCompanyIds[] = $companyData['id'];
            }

            DB::transaction(function () use ($city, $presentCompanyIds, &$result): void {
                $result['restaurants']['deactivated'] += $this->restaurants->deactivateMissingForCity(
                    $city,
                    $presentCompanyIds,
                );
            });
        }

        DB::transaction(function () use ($presentCityIds, &$result): void {
            $result['cities']['deactivated'] = $this->cities->deactivateMissing($presentCityIds);
            $result['restaurants']['deactivated'] += $this->restaurants->deactivateRestaurantsForInactiveCities();
            $result['addresses']['deactivated'] += $this->addresses->deactivateAddressesForInactiveRestaurants();
        });

        foreach ($this->restaurants->activeSynchronized() as $restaurant) {
            SyncRestaurantCatalog::dispatch($restaurant->id);
            $result['catalog_jobs']++;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function completeList(array $response, string $field): array
    {
        if (($response['hasNext'] ?? false) !== false) {
            throw ValidationException::withMessages([
                'hasNext' => ['The Dots discovery response must be complete.'],
            ]);
        }

        if (array_is_list($response)) {
            return $response;
        }

        $items = $response[$field] ?? $response['items'] ?? null;

        if (! is_array($items) || ! array_is_list($items)) {
            throw ValidationException::withMessages([
                $field => ['The Dots discovery response must contain a list.'],
            ]);
        }

        return $items;
    }

    /** @param array<string, mixed> $entity */
    private function isActiveDotsEntity(array $entity): bool
    {
        return (int) ($entity['status'] ?? 0) === 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cities
     */
    private function validateCities(array $cities): void
    {
        Validator::make(['cities' => $cities], [
            'cities' => ['array', 'list'],
            'cities.*' => ['required', 'array'],
            'cities.*.id' => ['required', 'uuid', 'distinct'],
            'cities.*.name' => ['required', 'string', 'max:255'],
            'cities.*.url' => ['required', 'string', 'max:255'],
            'cities.*.status' => ['required', 'integer'],
            'cities.*.centerCoordinates' => ['nullable', 'array'],
            'cities.*.centerCoordinates.latitude' => ['nullable', 'numeric'],
            'cities.*.centerCoordinates.longitude' => ['nullable', 'numeric'],
        ])->validate();
    }

    /** @param array<string, mixed> $city */
    private function validateCityDetails(array $city): void
    {
        Validator::make($city, [
            'id' => ['sometimes', 'uuid'],
            'name' => ['sometimes', 'string', 'max:255'],
            'url' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'integer'],
            'currency' => ['required', 'array'],
            'currency.token' => ['required', 'string', 'size:3'],
            'currency.formatted' => ['nullable', 'string', 'max:16'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'centerCoordinates' => ['nullable', 'array'],
            'centerCoordinates.latitude' => ['nullable', 'numeric'],
            'centerCoordinates.longitude' => ['nullable', 'numeric'],
        ])->validate();
    }

    /**
     * @param  array<int, array<string, mixed>>  $companies
     */
    private function validateCompanies(array $companies): void
    {
        Validator::make(['companies' => $companies], [
            'companies' => ['array', 'list'],
            'companies.*' => ['required', 'array'],
            'companies.*.id' => ['required', 'uuid', 'distinct'],
            'companies.*.name' => ['required', 'string', 'max:255'],
            'companies.*.url' => ['required', 'string', 'max:255'],
            'companies.*.status' => ['required', 'integer'],
            'companies.*.image' => ['nullable', 'string'],
            'companies.*.availablePaymentTypes' => ['nullable', 'array'],
            'companies.*.availableDeliveryTypes' => ['nullable', 'array'],
            'companies.*.schedule' => ['nullable', 'array'],
            'companies.*.deliveryTimeText' => ['nullable', 'string', 'max:255'],
            'companies.*.deliveryPriceText' => ['nullable', 'string', 'max:255'],
        ])->validate();
    }

    /** @param array<string, mixed> $company */
    private function validateCompanyDetails(array $company): void
    {
        Validator::make($company, [
            'id' => ['sometimes', 'uuid'],
            'name' => ['sometimes', 'string', 'max:255'],
            'url' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'integer'],
            'image' => ['nullable', 'string'],
            'availablePaymentTypes' => ['nullable', 'array'],
            'availableDeliveryTypes' => ['nullable', 'array'],
            'schedule' => ['nullable', 'array'],
            'deliveryTimeText' => ['nullable', 'string', 'max:255'],
            'deliveryPriceText' => ['nullable', 'string', 'max:255'],
            'addresses' => ['nullable', 'array', 'list'],
        ])->validate();
    }

    /**
     * @param  array<int, array<string, mixed>>  $addresses
     */
    private function validateAddresses(array $addresses): void
    {
        Validator::make(['addresses' => $addresses], [
            'addresses' => ['array', 'list'],
            'addresses.*' => ['required', 'array'],
            'addresses.*.id' => ['required', 'uuid', 'distinct'],
            'addresses.*.title' => ['required', 'string', 'max:255'],
            'addresses.*.status' => ['sometimes', 'integer'],
            'addresses.*.location' => ['nullable', 'array'],
            'addresses.*.location.latitude' => ['nullable', 'numeric'],
            'addresses.*.location.longitude' => ['nullable', 'numeric'],
            'addresses.*.polygon' => ['nullable', 'array'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $city
     * @return array<string, mixed>
     */
    private function cityAttributes(array $city): array
    {
        return [
            'name' => $city['name'],
            'slug' => $city['url'],
            'is_active' => $this->isActiveDotsEntity($city),
            'center_latitude' => Arr::get($city, 'centerCoordinates.latitude'),
            'center_longitude' => Arr::get($city, 'centerCoordinates.longitude'),
            'currency' => strtoupper($city['currency']['token']),
            'timezone' => $city['timezone'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $city
     * @param  array<string, mixed>  $company
     * @return array<string, mixed>
     */
    private function restaurantAttributes(array $city, array $company): array
    {
        return [
            'name' => $company['name'],
            'slug' => $company['url'],
            'currency' => strtoupper((string) Arr::get($city, 'currency.token', 'UAH')),
            'locale' => 'uk-UA',
            'timezone' => (string) ($city['timezone'] ?? 'Europe/Kyiv'),
            'is_active' => $this->isActiveDotsEntity($company),
            'image_url' => $company['image'] ?? null,
            'available_payment_types' => $company['availablePaymentTypes'] ?? [],
            'available_delivery_types' => $company['availableDeliveryTypes'] ?? [],
            'schedule' => $company['schedule'] ?? null,
            'delivery_time_text' => $company['deliveryTimeText'] ?? null,
            'delivery_price_text' => $company['deliveryPriceText'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    private function addressAttributes(array $address): array
    {
        return [
            'title' => $address['title'],
            'latitude' => Arr::get($address, 'location.latitude'),
            'longitude' => Arr::get($address, 'location.longitude'),
            'polygon' => $address['polygon'] ?? null,
            'is_active' => $this->isActiveDotsEntity($address + ['status' => 1]),
        ];
    }
}
