<?php

use App\Telegram\Handlers\CartHandler;
use App\Telegram\Handlers\CatalogHandler;
use App\Telegram\Handlers\CheckoutHandler;
use App\Telegram\Handlers\ContactHandler;
use App\Telegram\Handlers\StartHandler;
use SergiX44\Nutgram\Nutgram;

/** @var Nutgram $bot */
$bot->onCommand('start', StartHandler::class);
$bot->onContact(ContactHandler::class);
$bot->onCallbackQueryData('catalog', [CatalogHandler::class, 'catalog']);
$bot->onCallbackQueryData('category:{categoryId}', [CatalogHandler::class, 'category'])
    ->whereNumber('categoryId');
$bot->onCallbackQueryData('product:{categoryId}:{productId}', [CatalogHandler::class, 'product'])
    ->whereNumber('categoryId')
    ->whereNumber('productId');
$bot->onCallbackQueryData('main_menu', [CatalogHandler::class, 'mainMenu']);
$bot->onCallbackQueryData('menu:cart', [CartHandler::class, 'show']);
$bot->onCallbackQueryData('cart:add:{productId}', [CartHandler::class, 'add'])
    ->whereNumber('productId');
$bot->onCallbackQueryData('cart:inc:{itemId}', [CartHandler::class, 'increment'])
    ->whereNumber('itemId');
$bot->onCallbackQueryData('cart:dec:{itemId}', [CartHandler::class, 'decrement'])
    ->whereNumber('itemId');
$bot->onCallbackQueryData('cart:remove:{itemId}', [CartHandler::class, 'remove'])
    ->whereNumber('itemId');
$bot->onCallbackQueryData('cart:clear', [CartHandler::class, 'clear']);
$bot->onCallbackQueryData('cart:clear:confirm', [CartHandler::class, 'confirmClear']);
$bot->onCallbackQueryData('cart:clear:cancel', [CartHandler::class, 'cancelClear']);
$bot->onCallbackQueryData('cart:noop:{itemId}', [CartHandler::class, 'noop'])
    ->whereNumber('itemId');
$bot->onCallbackQueryData('checkout', [CheckoutHandler::class, 'checkout']);
$bot->onCallbackQueryData('order:confirm:{idempotencyKey}', [CheckoutHandler::class, 'confirm']);
$bot->onCallbackQueryData('order:cancel', [CheckoutHandler::class, 'cancel']);
$bot->onCallbackQueryData('order:refresh', [CheckoutHandler::class, 'refresh']);
