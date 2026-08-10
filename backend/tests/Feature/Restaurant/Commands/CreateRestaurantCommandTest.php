<?php

use App\Models\Restaurant;

it('creates an active restaurant using default options', function () {
    $companyId = '11111111-1111-1111-1111-111111111111';

    $this->artisan('restaurant:create', [
        'external_company_id' => $companyId,
        'name' => 'Test Restaurant',
        'slug' => 'test-restaurant',
    ])
        ->expectsOutputToContain("slug=test-restaurant external_company_id={$companyId}")
        ->assertSuccessful();

    $restaurant = Restaurant::query()->sole();

    expect($restaurant->external_company_id)->toBe($companyId)
        ->and($restaurant->name)->toBe('Test Restaurant')
        ->and($restaurant->slug)->toBe('test-restaurant')
        ->and($restaurant->currency)->toBe('UAH')
        ->and($restaurant->locale)->toBe('uk-UA')
        ->and($restaurant->timezone)->toBe('Europe/Kyiv')
        ->and($restaurant->is_active)->toBeTrue();
});

it('persists custom currency locale and timezone values', function () {
    $this->artisan('restaurant:create', [
        'external_company_id' => '22222222-2222-2222-2222-222222222222',
        'name' => 'Custom Restaurant',
        'slug' => 'custom-restaurant',
        '--currency' => 'EUR',
        '--locale' => 'en-US',
        '--timezone' => 'UTC',
    ])->assertSuccessful();

    $this->assertDatabaseHas('restaurants', [
        'external_company_id' => '22222222-2222-2222-2222-222222222222',
        'currency' => 'EUR',
        'locale' => 'en-US',
        'timezone' => 'UTC',
    ]);
});

it('creates an inactive restaurant when requested', function () {
    $this->artisan('restaurant:create', [
        'external_company_id' => '33333333-3333-3333-3333-333333333333',
        'name' => 'Inactive Restaurant',
        'slug' => 'inactive-restaurant',
        '--inactive' => true,
    ])->assertSuccessful();

    expect(Restaurant::query()->sole()->is_active)->toBeFalse();
});

it('normalizes currency to uppercase', function () {
    $this->artisan('restaurant:create', [
        'external_company_id' => '44444444-4444-4444-4444-444444444444',
        'name' => 'Currency Restaurant',
        'slug' => 'currency-restaurant',
        '--currency' => 'uah',
    ])->assertSuccessful();

    expect(Restaurant::query()->sole()->currency)->toBe('UAH');
});

it('fails for an invalid external company uuid and creates no restaurant', function () {
    $this->artisan('restaurant:create', [
        'external_company_id' => 'not-a-uuid',
        'name' => 'Invalid Restaurant',
        'slug' => 'invalid-restaurant',
    ])
        ->expectsOutputToContain('external company id')
        ->assertFailed();

    expect(Restaurant::query()->count())->toBe(0);
});

it('fails for an invalid slug and creates no restaurant', function () {
    $this->artisan('restaurant:create', [
        'external_company_id' => '55555555-5555-5555-5555-555555555555',
        'name' => 'Invalid Slug Restaurant',
        'slug' => 'invalid slug!',
    ])
        ->expectsOutputToContain('slug')
        ->assertFailed();

    expect(Restaurant::query()->count())->toBe(0);
});

it('fails for an invalid timezone and creates no restaurant', function () {
    $this->artisan('restaurant:create', [
        'external_company_id' => '66666666-6666-6666-6666-666666666666',
        'name' => 'Invalid Timezone Restaurant',
        'slug' => 'invalid-timezone-restaurant',
        '--timezone' => 'Not/AZone',
    ])
        ->expectsOutputToContain('timezone')
        ->assertFailed();

    expect(Restaurant::query()->count())->toBe(0);
});

it('fails for a duplicate external company id without creating another restaurant', function () {
    $companyId = '77777777-7777-7777-7777-777777777777';

    Restaurant::factory()->create(['external_company_id' => $companyId]);

    $this->artisan('restaurant:create', [
        'external_company_id' => $companyId,
        'name' => 'Duplicate Company Restaurant',
        'slug' => 'duplicate-company-restaurant',
    ])
        ->expectsOutputToContain('external company id')
        ->assertFailed();

    expect(Restaurant::query()->count())->toBe(1);
});

it('fails for a duplicate slug without creating another restaurant', function () {
    Restaurant::factory()->create(['slug' => 'duplicate-slug']);

    $this->artisan('restaurant:create', [
        'external_company_id' => '88888888-8888-8888-8888-888888888888',
        'name' => 'Duplicate Slug Restaurant',
        'slug' => 'duplicate-slug',
    ])
        ->expectsOutputToContain('slug')
        ->assertFailed();

    expect(Restaurant::query()->count())->toBe(1);
});

it('never outputs configured Dots tokens', function () {
    config()->set('services.dots.token', 'fake-public-token');
    config()->set('services.dots.account_token', 'fake-account-token');
    config()->set('services.dots.auth_token', 'fake-auth-token');

    $this->artisan('restaurant:create', [
        'external_company_id' => '99999999-9999-9999-9999-999999999999',
        'name' => 'Token Safe Restaurant',
        'slug' => 'token-safe-restaurant',
    ])
        ->doesntExpectOutputToContain('fake-public-token')
        ->doesntExpectOutputToContain('fake-account-token')
        ->doesntExpectOutputToContain('fake-auth-token')
        ->assertSuccessful();
});
