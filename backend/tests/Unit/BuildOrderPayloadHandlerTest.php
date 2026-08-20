<?php

use App\DTO\OrderCheckoutData;
use App\DTO\OrderFulfillmentContextData;
use App\Enums\PaymentType;
use App\Enums\ReceivingType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\City;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Services\Handlers\Order\BuildOrderPayloadHandler;
use Illuminate\Database\Eloquent\Collection;

function buildOrderCheckoutForPayload(string $type, array $items, int $deliveryType = 1): OrderCheckoutData
{
    $city = new City(['external_city_id' => 'city-external']);
    $restaurant = new Restaurant(['external_company_id' => 'company-external']);
    $cart = new Cart;
    $cart->setRelation('items', new Collection(array_map(
        static fn (array $item): CartItem => new CartItem($item),
        $items,
    )));

    $address = $type === 'pickup'
        ? new RestaurantAddress(['external_address_id' => 'address-external'])
        : null;

    $context = new OrderFulfillmentContextData(
        type: $type,
        receivingType: $type === 'pickup' ? ReceivingType::Pickup : ReceivingType::Delivery,
        city: $city,
        restaurant: $restaurant,
        restaurantAddress: $address,
        deliveryType: $deliveryType,
        deliveryPrice: '50.00',
        deliveryAddress: $type === 'delivery' ? ['street' => 'Main Street', 'house' => '10'] : null,
    );

    return new OrderCheckoutData(
        city: $city,
        restaurant: $restaurant,
        paymentType: PaymentType::Online,
        cart: $cart,
        customerName: 'Test User',
        customerPhone: '380931234567',
        receivingType: $context->receivingType,
        fulfillmentContext: $context,
    );
}

test('order payload maps delivery checkout to Dots fields', function (): void {
    $checkout = buildOrderCheckoutForPayload('delivery', [[
        'external_product_id' => 'product-1',
        'quantity' => 2,
    ]]);

    $payload = (new BuildOrderPayloadHandler)->handle($checkout, 0);

    expect($payload['orderFields'])->toMatchArray([
        'cityId' => 'city-external',
        'companyId' => 'company-external',
        'userName' => 'Test User',
        'userPhone' => '380931234567',
        'deliveryType' => 1,
        'paymentType' => 2,
        'deliveryTime' => 0,
        'deliveryAddressStreet' => 'Main Street',
        'deliveryAddressHouse' => '10',
    ])->and($payload['orderFields']['cartItems'])->toBe([
        ['id' => 'product-1', 'count' => 2],
    ]);
});

test('order payload maps pickup checkout to company address', function (): void {
    $checkout = buildOrderCheckoutForPayload('pickup', [[
        'external_product_id' => 'product-1',
        'quantity' => 1,
    ]], deliveryType: 2);

    $fields = (new BuildOrderPayloadHandler)->handle($checkout, 0)['orderFields'];

    expect($fields['companyAddressId'])->toBe('address-external')
        ->and($fields)->not->toHaveKey('deliveryAddressStreet')
        ->and($fields)->not->toHaveKey('deliveryAddressHouse')
        ->and($fields['deliveryType'])->toBe(2);
});

test('order payload preserves cart item order and quantities', function (): void {
    $checkout = buildOrderCheckoutForPayload('delivery', [
        ['external_product_id' => 'first', 'quantity' => 3],
        ['external_product_id' => 'second', 'quantity' => 1],
    ]);

    $items = (new BuildOrderPayloadHandler)->handle($checkout, 0)['orderFields']['cartItems'];

    expect($items)->toBe([
        ['id' => 'first', 'count' => 3],
        ['id' => 'second', 'count' => 1],
    ]);
});

test('order payload passes scheduled delivery time through unchanged', function (): void {
    $checkout = buildOrderCheckoutForPayload('delivery', [[
        'external_product_id' => 'product-1',
        'quantity' => 1,
    ]]);

    $fields = (new BuildOrderPayloadHandler)->handle($checkout, 1_800_000_000)['orderFields'];

    expect($fields['deliveryTime'])->toBe(1_800_000_000);
});
