<?php

use App\Integrations\Dots\DiscoveryApi;
use App\Models\City;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Services\Orchestrators\DotsTopologySynchronizationOrchestrator;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('creates and updates cities restaurants and addresses from complete Dots discovery', function () {
    Queue::fake();

    mockDiscovery([
        'cities' => ['items' => [dotsCity()], 'hasNext' => false],
        'cityDetails' => [dotsCity(['currency' => ['formatted' => '₴', 'token' => 'UAH'], 'timezone' => 'Europe/Kyiv'])],
        'companies' => ['11111111-1111-1111-1111-111111111111' => ['items' => [dotsCompany()], 'hasNext' => false]],
        'companyDetails' => ['22222222-2222-2222-2222-222222222222' => dotsCompany([
            'addresses' => [dotsAddress()],
        ])],
    ]);

    $result = app(DotsTopologySynchronizationOrchestrator::class)->sync();

    $city = City::query()->sole();
    $restaurant = Restaurant::query()->sole();
    $address = RestaurantAddress::query()->sole();

    expect($city->external_city_id)->toBe('11111111-1111-1111-1111-111111111111')
        ->and($city->slug)->toBe('chernigov')
        ->and($city->is_active)->toBeTrue()
        ->and($restaurant->city_id)->toBe($city->id)
        ->and($restaurant->external_company_id)->toBe('22222222-2222-2222-2222-222222222222')
        ->and($restaurant->available_payment_types)->toBe([1, 2])
        ->and($restaurant->available_delivery_types)->toBe([1, 2])
        ->and($address->restaurant_id)->toBe($restaurant->id)
        ->and($address->external_address_id)->toBe('33333333-3333-3333-3333-333333333333')
        ->and($result['catalog_jobs'])->toBe(1);
});

it('deactivates missing entities after a later complete Dots discovery without deleting rows', function () {
    Queue::fake();

    $city = City::factory()->create(['external_city_id' => '99999999-9999-9999-9999-999999999999']);
    $missingRestaurant = Restaurant::factory()->create([
        'city_id' => $city->id,
        'external_company_id' => '88888888-8888-8888-8888-888888888888',
        'is_active' => true,
    ]);
    $missingAddress = RestaurantAddress::factory()->create([
        'restaurant_id' => $missingRestaurant->id,
        'external_address_id' => '77777777-7777-7777-7777-777777777777',
        'is_active' => true,
    ]);

    mockDiscovery([
        'cities' => ['items' => [dotsCity(['id' => $city->external_city_id])], 'hasNext' => false],
        'cityDetails' => [dotsCity(['id' => $city->external_city_id])],
        'companies' => [$city->external_city_id => ['items' => [], 'hasNext' => false]],
        'companyDetails' => [],
    ]);

    app(DotsTopologySynchronizationOrchestrator::class)->sync();

    expect(City::query()->whereKey($city->id)->exists())->toBeTrue()
        ->and(Restaurant::query()->whereKey($missingRestaurant->id)->exists())->toBeTrue()
        ->and(RestaurantAddress::query()->whereKey($missingAddress->id)->exists())->toBeTrue()
        ->and($city->refresh()->is_active)->toBeTrue()
        ->and($missingRestaurant->refresh()->is_active)->toBeFalse()
        ->and($missingAddress->refresh()->is_active)->toBeFalse();
});

it('does not deactivate entities from an incomplete Dots discovery response', function () {
    Queue::fake();

    $city = City::factory()->create(['is_active' => true]);

    mockDiscovery([
        'cities' => ['items' => [], 'hasNext' => true],
        'cityDetails' => [],
        'companies' => [],
        'companyDetails' => [],
    ]);

    try {
        app(DotsTopologySynchronizationOrchestrator::class)->sync();
    } catch (ValidationException $exception) {
        expect($city->refresh()->is_active)->toBeTrue();

        throw $exception;
    }
})->throws(ValidationException::class);

function mockDiscovery(array $responses): void
{
    test()->mock(DiscoveryApi::class, function ($mock) use ($responses) {
        $mock->shouldReceive('refreshActiveCities')
            ->once()
            ->andReturn($responses['cities']);

        foreach ($responses['cityDetails'] as $cityDetails) {
            $mock->shouldReceive('getCity')
                ->once()
                ->with($cityDetails['id'])
                ->andReturn($cityDetails);
        }

        foreach ($responses['companies'] as $cityId => $companies) {
            $mock->shouldReceive('refreshCityCompanies')
                ->once()
                ->with($cityId)
                ->andReturn($companies);
        }

        foreach ($responses['companyDetails'] as $companyId => $companyDetails) {
            $mock->shouldReceive('getCompany')
                ->once()
                ->with($companyId)
                ->andReturn($companyDetails);
        }
    });
}

function dotsCity(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => '11111111-1111-1111-1111-111111111111',
        'name' => 'Чернигов',
        'url' => 'chernigov',
        'status' => 1,
        'currency' => [
            'formatted' => '₴',
            'token' => 'UAH',
        ],
        'centerCoordinates' => [
            'latitude' => 51.4982,
            'longitude' => 31.2893499,
        ],
    ], $overrides);
}

function dotsCompany(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => '22222222-2222-2222-2222-222222222222',
        'name' => 'Papa Jon',
        'image' => 'https://assets.dots.live/company.png',
        'status' => 1,
        'url' => 'papa-jon',
        'availablePaymentTypes' => [1, 2],
        'availableDeliveryTypes' => [1, 2],
        'schedule' => ['mon' => [['from' => '10:00', 'to' => '22:00']]],
        'deliveryTimeText' => '45 min',
        'deliveryPriceText' => '100 UAH',
        'addresses' => [],
    ], $overrides);
}

function dotsAddress(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => '33333333-3333-3333-3333-333333333333',
        'title' => 'Knyazhiy Zaton St., 9',
        'location' => [
            'latitude' => 50.402,
            'longitude' => 30.625218,
        ],
        'polygon' => [[50.402, 30.625218]],
    ], $overrides);
}
