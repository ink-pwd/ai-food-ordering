<?php

use App\DTO\OrderingBackend\CurrentPaymentData;
use App\DTO\OrderingBackend\OrderData;
use App\DTO\OrderingBackend\OrderItemData;
use App\DTO\OrderingBackend\OrderPaymentData;
use App\Telegram\Formatting\OrderMessageFormatter;

function orderForFormatterTest(string $status = 'created'): OrderData
{
    return new OrderData(
        id: 9,
        externalOrderId: 'dots-order',
        status: $status,
        failureMessage: null,
        receivingType: 'delivery',
        paymentType: 2,
        fulfillment: [
            'delivery_address' => [
                'street' => 'Main Street',
                'house' => '10',
                'flat' => '12',
            ],
        ],
        total: '100.00',
        currency: 'UAH',
        payment: new OrderPaymentData(
            status: 'ready',
            checkoutUrl: 'https://payment.example/checkout',
            paymentReceivedAt: null,
            qrReady: true,
        ),
        items: [
            new OrderItemData(
                productId: 12,
                externalProductId: 'product-external',
                name: 'Pizza',
                quantity: 1,
                unitPrice: '100.00',
                total: '100.00',
            ),
        ],
    );
}

test('order formatter renders the current order contract', function (): void {
    $message = (new OrderMessageFormatter)->format(orderForFormatterTest());

    expect($message)->toContain('✅ Замовлення створено.')
        ->and($message)->toContain('Замовлення #9')
        ->and($message)->toContain('📍 Адреса: Main Street, 10, 12')
        ->and($message)->toContain('Pizza')
        ->and($message)->toContain('Разом: 100.00 UAH');
});

test('order formatter adds failure notice for failed orders', function (): void {
    $message = (new OrderMessageFormatter)->format(orderForFormatterTest('failed'));

    expect($message)->toContain('❌ Не вдалося створити замовлення.');
});

test('payment formatter reflects ready, received, and pending states', function (): void {
    $formatter = new OrderMessageFormatter;

    $ready = new CurrentPaymentData(
        status: 'ready',
        checkoutUrl: 'https://payment.example/checkout',
        paymentReceivedAt: null,
        httpStatus: 200,
    );
    $received = new CurrentPaymentData(
        status: 'ready',
        checkoutUrl: 'https://payment.example/checkout',
        paymentReceivedAt: '2026-08-20T10:00:00+00:00',
        httpStatus: 200,
    );
    $pending = new CurrentPaymentData(
        status: 'pending',
        checkoutUrl: null,
        paymentReceivedAt: null,
        httpStatus: 202,
    );

    expect($formatter->payment($ready))->toContain('💳 Оплата готова.')
        ->and($formatter->payment($received))->toBe('✅ Оплату отримано.')
        ->and($formatter->payment($pending))->toBe('⏳ Платіжні дані ще готуються.');
});
