<?php

use App\DTO\OrderingBackend\CurrentPaymentData;
use App\DTO\OrderingBackend\OrderData;
use App\DTO\OrderingBackend\OrderPaymentData;
use App\Telegram\Formatting\OrderMessageFormatter;

function extendedFormatterOrder(
    string $status = 'created',
    string $receivingType = 'delivery',
    int $paymentType = 2,
    array $fulfillment = [],
): OrderData {
    return new OrderData(
        id: 15,
        externalOrderId: 'external',
        status: $status,
        failureMessage: null,
        receivingType: $receivingType,
        paymentType: $paymentType,
        fulfillment: $fulfillment,
        total: '250.00',
        currency: 'UAH',
        payment: new OrderPaymentData('pending', null, null, false),
        items: [],
    );
}

test('order formatter renders status heading and label', function (string $status, string $heading, string $label): void {
    $message = (new OrderMessageFormatter)->format(extendedFormatterOrder(status: $status));

    expect($message)->toStartWith($heading)
        ->and($message)->toContain('Статус: '.$label);
})->with([
    'creating' => ['creating', '⏳ Замовлення створюється.', 'Створюється'],
    'created' => ['created', '✅ Замовлення створено.', 'Створено'],
    'failed' => ['failed', '❌ Не вдалося створити замовлення.', 'Помилка'],
    'draft' => ['draft', '🧾 Замовлення', 'Чернетка'],
    'custom status' => ['paid', '🧾 Замовлення', 'paid'],
]);

test('order formatter renders receiving type labels', function (string $type, string $expected): void {
    expect((new OrderMessageFormatter)->format(extendedFormatterOrder(receivingType: $type)))
        ->toContain('Отримання: '.$expected);
})->with([
    'pickup' => ['pickup', '🏃 Самовивіз'],
    'delivery' => ['delivery', '🚚 Доставка'],
    'custom' => ['drone', 'drone'],
]);

test('order formatter renders payment type labels', function (int $type, string $expected): void {
    expect((new OrderMessageFormatter)->format(extendedFormatterOrder(paymentType: $type)))
        ->toContain('Оплата: '.$expected);
})->with([
    'cash' => [1, '💵 Готівка'],
    'online' => [2, '💳 Онлайн'],
    'terminal' => [3, '💳 Термінал'],
    'unknown' => [99, 'Тип #99'],
]);

test('order formatter renders fulfillment summary when available', function (array $fulfillment, ?string $expected): void {
    $message = (new OrderMessageFormatter)->format(extendedFormatterOrder(fulfillment: $fulfillment));

    if ($expected === null) {
        expect($message)->not->toContain('📍');

        return;
    }

    expect($message)->toContain($expected);
})->with([
    'string address' => [['delivery_address' => ' Main Street 10 '], '📍 Адреса: Main Street 10'],
    'array address' => [['delivery_address' => ['street' => 'Main', 'house' => '10', 'flat' => '12']], '📍 Адреса: Main, 10, 12'],
    'pickup address' => [['pickup_address' => 'Central'], '📍 Самовивіз: Central'],
    'none' => [[], null],
]);

test('payment formatter reflects payment state and optional QR notice', function (CurrentPaymentData $payment, ?string $notice, string $expected): void {
    expect((new OrderMessageFormatter)->payment($payment, $notice))->toBe($expected);
})->with([
    'received wins' => [new CurrentPaymentData('pending', null, 'received-at', 200), null, '✅ Оплату отримано.'],
    'ready' => [new CurrentPaymentData('ready', 'https://pay', null, 200), null, "💳 Оплата готова.\n\nНатисніть кнопку нижче, щоб перейти до захищеної сторінки оплати."],
    'pending' => [new CurrentPaymentData('pending', null, null, 202), null, '⏳ Платіжні дані ще готуються.'],
    'pending with qr notice' => [new CurrentPaymentData('pending', null, null, 202), 'QR ще готується.', "⏳ Платіжні дані ще готуються.\n\nQR ще готується."],
]);
