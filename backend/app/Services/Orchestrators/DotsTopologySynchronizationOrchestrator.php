<?php

namespace App\Services\Orchestrators;

use App\Integrations\Dots\DiscoveryApi;
use App\Models\City;
use App\Services\Handlers\Synchronization\CompleteDotsDiscoveryListHandler;
use App\Services\Handlers\Synchronization\DeactivateMissingDotsTopologyHandler;
use App\Services\Handlers\Synchronization\DeactivateMissingRestaurantsForCityHandler;
use App\Services\Handlers\Synchronization\DispatchRestaurantCatalogSyncJobsHandler;
use App\Services\Handlers\Synchronization\SynchronizeDotsCityHandler;
use App\Services\Handlers\Synchronization\SynchronizeDotsCompanyHandler;
use App\Services\Handlers\Synchronization\ValidateDotsTopologyPayloadHandler;

readonly class DotsTopologySynchronizationOrchestrator
{
    public function __construct(
        private DiscoveryApi $discoveryApi,
        private CompleteDotsDiscoveryListHandler $completeList,
        private ValidateDotsTopologyPayloadHandler $validator,
        private SynchronizeDotsCityHandler $synchronizeCity,
        private SynchronizeDotsCompanyHandler $synchronizeCompany,
        private DeactivateMissingRestaurantsForCityHandler $deactivateMissingRestaurants,
        private DeactivateMissingDotsTopologyHandler $deactivateMissingTopology,
        private DispatchRestaurantCatalogSyncJobsHandler $dispatchCatalogJobs,
    ) {
    }

    /**
     * @return array{
     *     cities: array<string, int>,
     *     restaurants: array<string, int>,
     *     addresses: array<string, int>,
     *     catalog_jobs: int
     * }
     */
    public function sync(): array
    {
        $citiesResponse =
            $this->discoveryApi
                ->refreshActiveCities();

        $cityItems =
            $this->completeList->handle(
                $citiesResponse,
                'cities',
            );

        $this->validator->handle(
            ValidateDotsTopologyPayloadHandler::CITIES,
            $cityItems,
        );

        $result = $this->emptyResult();

        /** @var array<int, string> $presentCityIds */
        $presentCityIds = [];

        foreach ($cityItems as $cityItem) {
            if (
                ! $this->isActiveDotsEntity(
                    $cityItem,
                )
            ) {
                continue;
            }

            /** @var string $cityId */
            $cityId = $cityItem['id'];

            $cityDetails =
                $this->discoveryApi->getCity(
                    $cityId,
                );

            $this->validator->handle(
                ValidateDotsTopologyPayloadHandler::CITY_DETAILS,
                $cityDetails,
            );

            $cityData =
                array_replace_recursive(
                    $cityItem,
                    $cityDetails,
                );

            $cityPersistence =
                $this->synchronizeCity->handle(
                    $cityData,
                );

            $city =
                $cityPersistence['city'];

            $result['cities'][
            $cityPersistence['state']
            ]++;

            /** @var string $resolvedCityId */
            $resolvedCityId =
                $cityData['id'];

            $presentCityIds[] =
                $resolvedCityId;

            $companiesResponse =
                $this->discoveryApi
                    ->refreshCityCompanies(
                        $resolvedCityId,
                    );

            $companyItems =
                $this->completeList->handle(
                    $companiesResponse,
                    'companies',
                );

            $this->validator->handle(
                ValidateDotsTopologyPayloadHandler::COMPANIES,
                $companyItems,
            );

            $presentCompanyIds =
                $this->synchronizeCompaniesForCity(
                    $city,
                    $cityData,
                    $companyItems,
                    $result,
                );

            $result['restaurants'][
            'deactivated'
            ] +=
                $this
                    ->deactivateMissingRestaurants
                    ->handle(
                        $city,
                        $presentCompanyIds,
                    );
        }

        $deactivated =
            $this->deactivateMissingTopology
                ->handle(
                    $presentCityIds,
                );

        $result['cities']['deactivated'] =
            $deactivated['cities'];

        $result[
        'restaurants'
        ]['deactivated'] +=
            $deactivated['restaurants'];

        $result[
        'addresses'
        ]['deactivated'] +=
            $deactivated['addresses'];

        $result['catalog_jobs'] =
            $this->dispatchCatalogJobs
                ->handle();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $cityData
     * @param  array<int, array<string, mixed>>  $companyItems
     * @param  array{
     *     cities: array<string, int>,
     *     restaurants: array<string, int>,
     *     addresses: array<string, int>,
     *     catalog_jobs: int
     * }  $result
     * @return array<int, string>
     */
    private function synchronizeCompaniesForCity(
        City $city,
        array $cityData,
        array $companyItems,
        array &$result,
    ): array {
        /** @var array<int, string> $presentCompanyIds */
        $presentCompanyIds = [];

        foreach ($companyItems as $companyItem) {
            if (
                ! $this->isActiveDotsEntity(
                    $companyItem,
                )
            ) {
                continue;
            }

            /** @var string $companyId */
            $companyId =
                $companyItem['id'];

            $companyDetails =
                $this->discoveryApi
                    ->getCompany(
                        $companyId,
                    );

            $this->validator->handle(
                ValidateDotsTopologyPayloadHandler::COMPANY_DETAILS,
                $companyDetails,
            );

            $companyData =
                array_replace_recursive(
                    $companyItem,
                    $companyDetails,
                );

            $companyResult =
                $this->synchronizeCompany
                    ->handle(
                        $city,
                        $cityData,
                        $companyData,
                    );

            $result['restaurants'][
            $companyResult[
            'restaurant_state'
            ]
            ]++;

            $this->accumulateAddressResult(
                $result,
                $companyResult['addresses'],
            );

            /** @var string $resolvedCompanyId */
            $resolvedCompanyId =
                $companyData['id'];

            $presentCompanyIds[] =
                $resolvedCompanyId;
        }

        return $presentCompanyIds;
    }

    /**
     * @param  array{
     *     cities: array<string, int>,
     *     restaurants: array<string, int>,
     *     addresses: array<string, int>,
     *     catalog_jobs: int
     * }  $result
     * @param  array{
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     deactivated: int
     * }  $addressResult
     */
    private function accumulateAddressResult(
        array &$result,
        array $addressResult,
    ): void {
        foreach (
            [
                'created',
                'updated',
                'unchanged',
                'deactivated',
            ]
            as $state
        ) {
            $result[
            'addresses'
            ][$state] +=
                $addressResult[$state];
        }
    }

    /**
     * @return array{
     *     cities: array{
     *         created: int,
     *         updated: int,
     *         unchanged: int,
     *         deactivated: int
     *     },
     *     restaurants: array{
     *         created: int,
     *         updated: int,
     *         unchanged: int,
     *         deactivated: int
     *     },
     *     addresses: array{
     *         created: int,
     *         updated: int,
     *         unchanged: int,
     *         deactivated: int
     *     },
     *     catalog_jobs: int
     * }
     */
    private function emptyResult(): array
    {
        return [
            'cities' => [
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'deactivated' => 0,
            ],

            'restaurants' => [
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'deactivated' => 0,
            ],

            'addresses' => [
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'deactivated' => 0,
            ],

            'catalog_jobs' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private function isActiveDotsEntity(
        array $entity,
    ): bool {
        /** @var int|string $status */
        $status =
            $entity['status'] ?? 0;

        return (int) $status === 1;
    }
}
