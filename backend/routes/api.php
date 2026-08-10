<?php

use App\Http\Controllers\Api\Cart\CartClearController;
use App\Http\Controllers\Api\Cart\CartItemDestroyController;
use App\Http\Controllers\Api\Cart\CartItemStoreController;
use App\Http\Controllers\Api\Cart\CartItemUpdateController;
use App\Http\Controllers\Api\Cart\CartShowController;
use App\Http\Controllers\Api\Cart\CartStoreController;
use App\Http\Controllers\Api\Order\OrderCurrentController;
use App\Http\Controllers\Api\Order\OrderStoreController;
use App\Http\Controllers\Api\Restaurant\RestaurantCatalogController;
use App\Http\Controllers\Api\Restaurant\RestaurantCategoryController;
use App\Http\Controllers\Api\Restaurant\RestaurantCategoryProductController;
use App\Http\Controllers\Api\Restaurant\RestaurantProductController;
use App\Http\Controllers\Api\Restaurant\RestaurantProductSearchController;
use App\Http\Controllers\Api\Session\SessionContactController;
use App\Http\Controllers\Api\Session\SessionStoreController;
use Illuminate\Support\Facades\Route;

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
