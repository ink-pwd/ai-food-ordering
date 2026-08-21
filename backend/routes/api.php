<?php

use App\Http\Controllers\Api\Cart\CartClearController;
use App\Http\Controllers\Api\Cart\CartItemDestroyController;
use App\Http\Controllers\Api\Cart\CartItemStoreController;
use App\Http\Controllers\Api\Cart\CartItemUpdateController;
use App\Http\Controllers\Api\Cart\CartShowController;
use App\Http\Controllers\Api\Cart\CartStoreController;
use App\Http\Controllers\Api\City\CityIndexController;
use App\Http\Controllers\Api\Order\OrderCurrentController;
use App\Http\Controllers\Api\Order\OrderPaymentController;
use App\Http\Controllers\Api\Order\OrderPaymentQrController;
use App\Http\Controllers\Api\Order\OrderStoreController;
use App\Http\Controllers\Api\Order\OrderTrackingController;
use App\Http\Controllers\Api\Restaurant\RestaurantCatalogController;
use App\Http\Controllers\Api\Restaurant\RestaurantCategoryController;
use App\Http\Controllers\Api\Restaurant\RestaurantCategoryProductController;
use App\Http\Controllers\Api\Restaurant\RestaurantProductController;
use App\Http\Controllers\Api\Restaurant\RestaurantProductSearchController;
use App\Http\Controllers\Api\Session\SessionCityController;
use App\Http\Controllers\Api\Session\SessionContactController;
use App\Http\Controllers\Api\Session\SessionDeliveryAddressController;
use App\Http\Controllers\Api\Session\SessionExitController;
use App\Http\Controllers\Api\Session\SessionFulfillmentController;
use App\Http\Controllers\Api\Session\SessionFulfillmentOptionsController;
use App\Http\Controllers\Api\Session\SessionOtpController;
use App\Http\Controllers\Api\Session\SessionOtpVerifyController;
use App\Http\Controllers\Api\Session\SessionPaymentController;
use App\Http\Controllers\Api\Session\SessionPickupAddressController;
use App\Http\Controllers\Api\Session\SessionPickupAddressIndexController;
use App\Http\Controllers\Api\Session\SessionRestaurantController;
use App\Http\Controllers\Api\Session\SessionRestaurantIndexController;
use App\Http\Controllers\Api\Session\SessionStoreController;
use Illuminate\Support\Facades\Route;

Route::get('cities', CityIndexController::class)
    ->middleware('internal.api')
    ->name('internal.cities.index');

Route::post('carts', CartStoreController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.carts.store');

Route::get('carts/current', CartShowController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.carts.current.show');

Route::post('sessions', SessionStoreController::class)
    ->middleware('internal.api')
    ->name('internal.sessions.store');

Route::put('sessions/current/contact', SessionContactController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.sessions.contact.update');

Route::post('sessions/current/otp', SessionOtpController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.sessions.otp.store');

Route::post('sessions/current/otp/verify', SessionOtpVerifyController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.sessions.otp.verify');

Route::put('sessions/current/city', SessionCityController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.sessions.city.update');

Route::get('sessions/current/restaurants', SessionRestaurantIndexController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.sessions.restaurants.index');

Route::put('sessions/current/restaurant', SessionRestaurantController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.sessions.restaurant.update');

Route::delete('sessions/current', SessionExitController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.sessions.current.destroy');

Route::get('sessions/current/fulfillment-options', SessionFulfillmentOptionsController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.sessions.fulfillment-options.index');

Route::put('sessions/current/fulfillment', SessionFulfillmentController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.sessions.fulfillment.update');

Route::get('sessions/current/pickup-addresses', SessionPickupAddressIndexController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.sessions.pickup-addresses.index');

Route::put('sessions/current/pickup-address', SessionPickupAddressController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.sessions.pickup-address.update');

Route::post('sessions/current/delivery-address', SessionDeliveryAddressController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.sessions.delivery-address.store');

Route::get('restaurants/{restaurant}/categories', [RestaurantCategoryController::class, 'index'])
    ->middleware('internal.api')
    ->name('internal.restaurants.categories.index');

Route::get('restaurants/{restaurant}/categories/{category}/products', [RestaurantCategoryProductController::class, 'index'])
    ->whereNumber('category')
    ->middleware('internal.api')
    ->name('internal.restaurants.categories.products.index');

Route::get('restaurants/{restaurant}/products/search', [RestaurantProductSearchController::class, 'index'])
    ->middleware('internal.api')
    ->name('internal.restaurants.products.search');

Route::get('restaurants/{restaurant}/products/{product}', [RestaurantProductController::class, 'show'])
    ->whereNumber('product')
    ->middleware('internal.api')
    ->name('internal.restaurants.products.show');

Route::get('restaurants/{restaurant}/catalog', [RestaurantCatalogController::class, 'show'])
    ->middleware('internal.api')
    ->name('internal.restaurants.catalog.show');

Route::post('carts/current/items', CartItemStoreController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.carts.items.store');

Route::patch(
    'carts/current/items/{item}',
    CartItemUpdateController::class,
)
    ->whereNumber('item')
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.carts.items.update');

Route::delete(
    'carts/current/items/{item}',
    CartItemDestroyController::class,
)
    ->whereNumber('item')
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.carts.items.destroy');

Route::delete(
    'carts/current/items',
    CartClearController::class,
)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.carts.items.clear');

Route::post('orders', OrderStoreController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.orders.store');

Route::get('orders/current', OrderCurrentController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.orders.current.show');

Route::get('orders/{order}/tracking', OrderTrackingController::class)
    ->whereNumber('order')
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.orders.tracking.show');

Route::get('orders/current/payment', OrderPaymentController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.orders.current.payment.show');

Route::get('orders/current/payment/qr', OrderPaymentQrController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.orders.current.payment.qr.show');

Route::put('sessions/current/payment', SessionPaymentController::class)
    ->middleware(['internal.api', 'internal.session'])
    ->name('internal.sessions.payment.update');
