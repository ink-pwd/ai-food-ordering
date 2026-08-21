<?php

use App\Telegram\Handlers\CartHandler;
use App\Telegram\Handlers\CatalogHandler;
use App\Telegram\Handlers\CheckoutHandler;
use App\Telegram\Handlers\CitySelectionHandler;
use App\Telegram\Handlers\ContactHandler;
use App\Telegram\Handlers\ExitHandler;
use App\Telegram\Handlers\FulfillmentHandler;
use App\Telegram\Handlers\OrderTrackingHandler;
use App\Telegram\Handlers\OtpHandler;
use App\Telegram\Handlers\OtpTextHandler;
use App\Telegram\Handlers\RestaurantSelectionHandler;
use App\Telegram\Handlers\StartHandler;
use SergiX44\Nutgram\Nutgram;

/** @var Nutgram $bot */
$bot->onCommand('start', StartHandler::class);
$bot->onCommand('exit', [ExitHandler::class, 'command']);
$bot->onContact(ContactHandler::class);
$bot->onText('{code}', OtpTextHandler::class)
    ->where('code', '[0-9]+');
$bot->onText('{address}', [FulfillmentHandler::class, 'address']);
$bot->onCallbackQueryData('exit', ExitHandler::class);
$bot->onCallbackQueryData('otp:resend', [OtpHandler::class, 'resend']);
$bot->onCallbackQueryData('fulfillment:menu:{restaurantId}:{fingerprint}', [FulfillmentHandler::class, 'menu'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('fulfillment:delivery:{restaurantId}:{fingerprint}', [FulfillmentHandler::class, 'delivery'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('fulfillment:pickup:{restaurantId}:{fingerprint}', [FulfillmentHandler::class, 'pickup'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('delivery:retry:{restaurantId}:{fingerprint}', [FulfillmentHandler::class, 'retryDeliveryAddress'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('delivery:type:{type}:{restaurantId}:{fingerprint}', [FulfillmentHandler::class, 'deliveryAddressType'])
    ->whereNumber('type')
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('pickup:{restaurantAddressId}:{restaurantId}:{fingerprint}', [FulfillmentHandler::class, 'selectPickupAddress'])
    ->whereNumber('restaurantAddressId')
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('city:{cityId}', [CitySelectionHandler::class, 'select'])
    ->whereNumber('cityId');
$bot->onCallbackQueryData('restaurant:{restaurantId}', [RestaurantSelectionHandler::class, 'select'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('catalog:{restaurantId}:{fingerprint}', [CatalogHandler::class, 'catalog'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('category:{categoryId}:{restaurantId}:{fingerprint}', [CatalogHandler::class, 'category'])
    ->whereNumber('categoryId')
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('product:{categoryId}:{productId}:{restaurantId}:{fingerprint}', [CatalogHandler::class, 'product'])
    ->whereNumber('categoryId')
    ->whereNumber('productId')
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('main_menu:{restaurantId}:{fingerprint}', [CatalogHandler::class, 'mainMenu'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('post_order:main_menu:{restaurantId}:{fingerprint}', [CatalogHandler::class, 'postOrderMainMenu'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('menu:cart:{restaurantId}:{fingerprint}', [CartHandler::class, 'show'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('menu:tracking:{restaurantId}:{fingerprint}', [OrderTrackingHandler::class, 'prompt'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('tracking:back:{restaurantId}:{fingerprint}', [OrderTrackingHandler::class, 'back'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('cart:add:{productId}:{restaurantId}:{fingerprint}', [CartHandler::class, 'add'])
    ->whereNumber('productId')
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('cart:inc:{itemId}:{restaurantId}:{fingerprint}', [CartHandler::class, 'increment'])
    ->whereNumber('itemId')
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('cart:dec:{itemId}:{restaurantId}:{fingerprint}', [CartHandler::class, 'decrement'])
    ->whereNumber('itemId')
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('cart:remove:{itemId}:{restaurantId}:{fingerprint}', [CartHandler::class, 'remove'])
    ->whereNumber('itemId')
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('cart:clear:{restaurantId}:{fingerprint}', [CartHandler::class, 'clear'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('cart:clear:confirm:{restaurantId}:{fingerprint}', [CartHandler::class, 'confirmClear'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('cart:clear:cancel:{restaurantId}:{fingerprint}', [CartHandler::class, 'cancelClear'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('cart:noop:{itemId}:{restaurantId}:{fingerprint}', [CartHandler::class, 'noop'])
    ->whereNumber('itemId')
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('checkout:{restaurantId}:{fingerprint}', [CheckoutHandler::class, 'checkout'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('oc:{idempotencyKey}:{restaurantId}:{fingerprint}', [CheckoutHandler::class, 'confirm'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('order:cancel:{restaurantId}:{fingerprint}', [CheckoutHandler::class, 'cancel'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('order:refresh:{restaurantId}:{fingerprint}', [CheckoutHandler::class, 'refresh'])
    ->whereNumber('restaurantId');
$bot->onCallbackQueryData('payment:refresh:{restaurantId}:{fingerprint}', [CheckoutHandler::class, 'refreshPayment'])
    ->whereNumber('restaurantId');

$bot->onCallbackQueryData('checkout:payment:{paymentType}:{restaurantId}:{fingerprint}', [CheckoutHandler::class, 'payment'], )
    ->whereNumber('paymentType')
    ->whereNumber('restaurantId');
