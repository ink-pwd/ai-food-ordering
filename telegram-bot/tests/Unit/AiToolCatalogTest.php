<?php

use App\Services\Ai\AiToolCatalog;

it('exposes only the limited cart and tracking tools', function (): void {
    $definitions = (new AiToolCatalog)->definitions();
    $names = array_map(
        static fn ($definition): string => $definition->name,
        $definitions,
    );

    expect($names)->toBe([
        'search_products',
        'get_cart',
        'add_to_cart',
        'set_cart_item_quantity',
        'remove_cart_item',
        'get_order_tracking',
    ])->and($names)->not->toContain(
        'checkout',
        'create_order',
        'payment',
    );
});

it('describes the existing backend substring search retry strategy', function (): void {
    $definitions = (new AiToolCatalog)->definitions();
    $search = $definitions[0];

    expect($search->name)->toBe('search_products')
        ->and($search->description)->toContain('case-insensitive substring matching')
        ->and($search->description)->toContain('retry with a shorter distinctive word')
        ->and($search->parameters[0]->description)->toContain('stable word fragment');
});
