<?php

namespace App\Services\Handlers\Synchronization;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class ValidateDotsTopologyPayloadHandler
{
    public const string CITIES = 'cities';

    public const string CITY_DETAILS = 'city_details';

    public const string COMPANIES = 'companies';

    public const string COMPANY_DETAILS = 'company_details';

    public const string ADDRESSES = 'addresses';

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>  $payload
     */
    public function handle(
        string $type,
        array $payload,
    ): void {
        match ($type) {
            self::CITIES => $this->validateCities($payload),

            self::CITY_DETAILS => $this->validateCityDetails($payload),

            self::COMPANIES => $this->validateCompanies($payload),

            self::COMPANY_DETAILS => $this->validateCompanyDetails($payload),

            self::ADDRESSES => $this->validateAddresses($payload),

            default => throw new InvalidArgumentException(
                "Unsupported Dots topology payload type: {$type}",
            ),
        };
    }

    /**
     * @param  array<int|string, mixed>  $cities
     */
    private function validateCities(array $cities): void
    {
        Validator::make(
            ['cities' => $cities],
            [
                'cities' => ['array', 'list'],
                'cities.*' => ['required', 'array'],
                'cities.*.id' => [
                    'required',
                    'uuid',
                    'distinct',
                ],
                'cities.*.name' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'cities.*.url' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'cities.*.status' => [
                    'required',
                    'integer',
                ],
                'cities.*.centerCoordinates' => [
                    'nullable',
                    'array',
                ],
                'cities.*.centerCoordinates.latitude' => [
                    'nullable',
                    'numeric',
                ],
                'cities.*.centerCoordinates.longitude' => [
                    'nullable',
                    'numeric',
                ],
            ],
        )->validate();
    }

    /**
     * @param  array<int|string, mixed>  $city
     */
    private function validateCityDetails(array $city): void
    {
        Validator::make(
            $city,
            [
                'id' => [
                    'sometimes',
                    'uuid',
                ],
                'name' => [
                    'sometimes',
                    'string',
                    'max:255',
                ],
                'url' => [
                    'sometimes',
                    'string',
                    'max:255',
                ],
                'status' => [
                    'sometimes',
                    'integer',
                ],
                'currency' => [
                    'required',
                    'array',
                ],
                'currency.token' => [
                    'required',
                    'string',
                    'size:3',
                ],
                'currency.formatted' => [
                    'nullable',
                    'string',
                    'max:16',
                ],
                'timezone' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
                'centerCoordinates' => [
                    'nullable',
                    'array',
                ],
                'centerCoordinates.latitude' => [
                    'nullable',
                    'numeric',
                ],
                'centerCoordinates.longitude' => [
                    'nullable',
                    'numeric',
                ],
            ],
        )->validate();
    }

    /**
     * @param  array<int|string, mixed>  $companies
     */
    private function validateCompanies(
        array $companies,
    ): void {
        Validator::make(
            ['companies' => $companies],
            [
                'companies' => [
                    'array',
                    'list',
                ],
                'companies.*' => [
                    'required',
                    'array',
                ],
                'companies.*.id' => [
                    'required',
                    'uuid',
                    'distinct',
                ],
                'companies.*.name' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'companies.*.url' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'companies.*.status' => [
                    'required',
                    'integer',
                ],
                'companies.*.image' => [
                    'nullable',
                    'string',
                ],
                'companies.*.availablePaymentTypes' => [
                    'nullable',
                    'array',
                    'list',
                ],
                'companies.*.availablePaymentTypes.*' => [
                    'required',
                    'array',
                ],
                'companies.*.availablePaymentTypes.*.type' => [
                    'required',
                    'integer',
                ],
                'companies.*.availablePaymentTypes.*.title' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
                'companies.*.availableDeliveryTypes' => [
                    'nullable',
                    'array',
                ],
                'companies.*.schedule' => [
                    'nullable',
                    'array',
                ],
                'companies.*.deliveryTimeText' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
                'companies.*.deliveryPriceText' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
            ],
        )->validate();
    }

    /**
     * @param  array<int|string, mixed>  $company
     */
    private function validateCompanyDetails(
        array $company,
    ): void {
        Validator::make(
            $company,
            [
                'id' => [
                    'sometimes',
                    'uuid',
                ],
                'name' => [
                    'sometimes',
                    'string',
                    'max:255',
                ],
                'url' => [
                    'sometimes',
                    'string',
                    'max:255',
                ],
                'status' => [
                    'sometimes',
                    'integer',
                ],
                'image' => [
                    'nullable',
                    'string',
                ],
                'availablePaymentTypes' => [
                    'nullable',
                    'array',
                    'list',
                ],
                'availablePaymentTypes.*' => [
                    'required',
                    'array',
                ],
                'availablePaymentTypes.*.type' => [
                    'required',
                    'integer',
                ],
                'availablePaymentTypes.*.title' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
                'availableDeliveryTypes' => [
                    'nullable',
                    'array',
                ],
                'schedule' => [
                    'nullable',
                    'array',
                ],
                'deliveryTimeText' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
                'deliveryPriceText' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
                'addresses' => [
                    'nullable',
                    'array',
                    'list',
                ],
            ],
        )->validate();
    }

    /**
     * @param  array<int|string, mixed>  $addresses
     */
    private function validateAddresses(
        array $addresses,
    ): void {
        Validator::make(
            ['addresses' => $addresses],
            [
                'addresses' => [
                    'array',
                    'list',
                ],
                'addresses.*' => [
                    'required',
                    'array',
                ],
                'addresses.*.id' => [
                    'required',
                    'uuid',
                    'distinct',
                ],
                'addresses.*.title' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'addresses.*.status' => [
                    'sometimes',
                    'integer',
                ],
                'addresses.*.location' => [
                    'nullable',
                    'array',
                ],
                'addresses.*.location.latitude' => [
                    'nullable',
                    'numeric',
                ],
                'addresses.*.location.longitude' => [
                    'nullable',
                    'numeric',
                ],
                'addresses.*.polygon' => [
                    'nullable',
                    'array',
                ],
            ],
        )->validate();
    }
}
