<?php

use App\Telegram\Support\OrderTrackingPromptStore;

test('order tracking prompt store keeps only ephemeral reply context', function (): void {
    $store = new OrderTrackingPromptStore;

    $store->put(
        chatId: 100,
        messageId: 200,
        restaurantId: 7,
        fingerprint: 'fingerprint',
    );

    expect($store->get(100, 200))->toBe([
        'restaurant_id' => 7,
        'fingerprint' => 'fingerprint',
    ])->and($store->get(100, 201))->toBeNull();

    $store->forget(100, 200);

    expect($store->get(100, 200))->toBeNull();
});

test('order tracking prompt store can clear prompts for one chat', function (): void {
    $store = new OrderTrackingPromptStore;

    $store->put(
        chatId: 100,
        messageId: 200,
        restaurantId: 7,
        fingerprint: 'fingerprint',
    );
    $store->put(
        chatId: 100,
        messageId: 201,
        restaurantId: 7,
        fingerprint: 'fingerprint',
    );
    $store->put(
        chatId: 101,
        messageId: 300,
        restaurantId: 8,
        fingerprint: 'other',
    );

    $store->forgetChat(100);

    expect($store->get(100, 200))->toBeNull()
        ->and($store->get(100, 201))->toBeNull()
        ->and($store->get(101, 300))->toBe([
            'restaurant_id' => 8,
            'fingerprint' => 'other',
        ]);
});
