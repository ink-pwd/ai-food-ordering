<?php

use App\Http\Requests\AddCartItemRequest;
use App\Http\Requests\CreateCartRequest;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Requests\CreateSessionRequest;
use App\Http\Requests\RestaurantProductSearchRequest;
use App\Http\Requests\SelectFulfillmentTypeRequest;
use App\Http\Requests\SelectPickupAddressRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Requests\UpdateSessionContactRequest;
use App\Http\Requests\ValidateDeliveryAddressRequest;
use Illuminate\Foundation\Http\FormRequest;

/** @param class-string<FormRequest> $requestClass */
test('request validation keeps client and server owned fields explicit', function (string $requestClass, string $clientField, string $clientRule, string $serverField, string $serverRule): void {
    $request = new $requestClass;
    $rules = $request->rules();

    expect($request->authorize())->toBeTrue()
        ->and($rules)->toHaveKey($clientField)
        ->and($rules[$clientField])->toContain($clientRule)
        ->and($rules)->toHaveKey($serverField)
        ->and($rules[$serverField])->toContain($serverRule);
})->with([
    'add cart item' => [AddCartItemRequest::class, 'product_id', 'required', 'cart_id', 'missing'],
    'create cart' => [CreateCartRequest::class, 'session_id', 'missing', 'items', 'missing'],
    'create order' => [CreateOrderRequest::class, 'delivery_time', 'required', 'company_id', 'missing'],
    'create session' => [CreateSessionRequest::class, 'channel', 'required', 'restaurant_id', 'prohibited'],
    'product search' => [RestaurantProductSearchRequest::class, 'q', 'required', 'limit', 'sometimes'],
    'select fulfillment' => [SelectFulfillmentTypeRequest::class, 'type', 'required', 'city_id', 'prohibited'],
    'select pickup address' => [SelectPickupAddressRequest::class, 'restaurant_address_id', 'required', 'external_address_id', 'prohibited'],
    'update cart item' => [UpdateCartItemRequest::class, 'quantity', 'required', 'product_id', 'missing'],
    'update contact' => [UpdateSessionContactRequest::class, 'name', 'required', 'phone_verified', 'prohibited'],
    'validate delivery address' => [ValidateDeliveryAddressRequest::class, 'street', 'required', 'latitude', 'prohibited'],
]);
