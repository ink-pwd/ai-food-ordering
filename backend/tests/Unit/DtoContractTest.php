<?php

use App\DTO\CustomerContactData;
use App\DTO\DeliveryAddressValidationResultData;
use App\DTO\OrderCheckoutData;
use App\DTO\OrderFulfillmentContextData;
use App\DTO\OrderFulfillmentSnapshotData;
use App\DTO\OrderPricingData;
use App\DTO\OtpChallengeData;
use App\DTO\ProductSynchronizationResultData;
use App\DTO\SessionData;
use App\Enums\PaymentType;
use App\Enums\ReceivingType;
use App\Models\Cart;
use App\Models\City;
use App\Models\Restaurant;

test('customer contact dto keeps normalized checkout contact', function (): void {
    $contact = new CustomerContactData('Test User', '380931234567');

    expect($contact->name)->toBe('Test User')->and($contact->phone)->toBe('380931234567');
});

test('delivery validation result keeps availability result and session', function (): void {
    $session = new SessionData('s', 1, 2, 'telegram', 'chat', 'active', [], 'created', 'expires');
    $result = new DeliveryAddressValidationResultData($session, false, 'outside_delivery_zone', null, null);

    expect($result->session)->toBe($session)
        ->and($result->deliveryAvailable)->toBeFalse()
        ->and($result->reason)->toBe('outside_delivery_zone');
});

test('order fulfillment context keeps delivery payload untouched', function (): void {
    $city = new City;
    $restaurant = new Restaurant;
    $address = ['street' => 'Main', 'house' => '10', 'note' => 'Gate'];
    $context = new OrderFulfillmentContextData('delivery', ReceivingType::Delivery, $city, $restaurant, null, 1, '50.00', $address);

    expect($context->deliveryAddress)->toBe($address)
        ->and($context->receivingType)->toBe(ReceivingType::Delivery);
});

test('order pricing dto keeps validation snapshot and validated total', function (): void {
    $snapshot = new OrderFulfillmentSnapshotData(1, 'c', 2, 'r', 'delivery', 1, '50.00', '50.00', 2, null, null, []);
    $pricing = new OrderPricingData(['success' => true], '150.00', $snapshot);

    expect($pricing->validation)->toBe(['success' => true])
        ->and($pricing->validatedTotal)->toBe('150.00')
        ->and($pricing->fulfillmentSnapshot)->toBe($snapshot);
});

test('order checkout dto keeps resolved domain objects', function (): void {
    $city = new City;
    $restaurant = new Restaurant;
    $cart = new Cart;
    $context = new OrderFulfillmentContextData('delivery', ReceivingType::Delivery, $city, $restaurant, null, 1, 0, []);
    $checkout = new OrderCheckoutData($city, $restaurant, PaymentType::Cash, $cart, 'Name', '123', ReceivingType::Delivery, $context);

    expect($checkout->city)->toBe($city)
        ->and($checkout->restaurant)->toBe($restaurant)
        ->and($checkout->cart)->toBe($cart)
        ->and($checkout->paymentType)->toBe(PaymentType::Cash);
});

test('session data preserves created and expiration strings', function (): void {
    $session = SessionData::fromArray([
        'id' => 's', 'channel' => 'api', 'external_session_id' => 'e', 'status' => 'active', 'metadata' => [],
        'created_at' => 'created-value', 'expires_at' => 'expires-value',
    ]);

    expect($session->createdAt)->toBe('created-value')->and($session->expiresAt)->toBe('expires-value');
});

test('product synchronization result allows zero counters without changing shape', function (): void {
    expect((new ProductSynchronizationResultData(0, 0, 0, 0, 0))->toArray())->toBe([
        'created' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'relations_attached' => 0,
        'relations_detached' => 0,
    ]);
});

test('otp challenge can reach zero remaining attempts immutably', function (): void {
    $challenge = new OtpChallengeData('s', '+380931234567', 'hash', 1, 'expires', 'resend');
    $updated = $challenge->withAttemptsRemaining(0);

    expect($challenge->attemptsRemaining)->toBe(1)
        ->and($updated->attemptsRemaining)->toBe(0)
        ->and($updated)->not->toBe($challenge);
});
