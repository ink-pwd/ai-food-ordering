<?php

use App\Telegram\Support\AiPromptStore;

it('keeps only the latest ai prompt for a chat', function (): void {
    $store = new AiPromptStore;

    $store->put(
        chatId: 100,
        messageId: 200,
        restaurantId: 7,
        fingerprint: 'first',
    );
    $store->put(
        chatId: 100,
        messageId: 201,
        restaurantId: 8,
        fingerprint: 'second',
    );

    expect($store->get(100, 200))->toBeNull()
        ->and($store->get(100, 201)?->restaurantId)->toBe(8)
        ->and($store->get(100, 201)?->fingerprint)->toBe('second');
});

it('clears ai prompts only for the requested chat', function (): void {
    $store = new AiPromptStore;

    $store->put(100, 200, 7, 'first');
    $store->put(101, 300, 8, 'other');
    $store->forgetChat(100);

    expect($store->get(100, 200))->toBeNull()
        ->and($store->get(101, 300)?->restaurantId)->toBe(8);
});
