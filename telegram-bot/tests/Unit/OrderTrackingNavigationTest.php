<?php

use App\Telegram\Keyboards\MainMenuKeyboard;
use App\Telegram\Keyboards\OrderKeyboard;
use App\Telegram\Keyboards\OrderTrackingKeyboard;

/**
 * @return list<array{text: string, callback_data: string|null}>
 */
function inlineButtonsForTrackingTest(object $keyboard): array
{
    return array_map(
        static fn (array $row): array => [
            'text' => $row[0]->text,
            'callback_data' => $row[0]->callback_data,
        ],
        $keyboard->inline_keyboard,
    );
}

test('main menu places order tracking immediately before exit', function (): void {
    $buttons = inlineButtonsForTrackingTest(
        (new MainMenuKeyboard)->make('7:fingerprint'),
    );

    expect(array_column($buttons, 'text'))->toBe([
        '🍕 Каталог',
        '🛒 Кошик',
        '🚚 Спосіб отримання',
        '📍 Де замовлення?',
        '🚪 Вийти',
    ])->and($buttons[3]['callback_data'])
        ->toBe('menu:tracking:7:fingerprint');
});

test('post order keyboards return to main menu instead of exiting', function (): void {
    $keyboard = new OrderKeyboard;

    foreach ([
        $keyboard->order('created', '7:fingerprint'),
        $keyboard->paymentPending('7:fingerprint'),
        $keyboard->paymentReady('https://payment.example', '7:fingerprint'),
        $keyboard->statusCheck('7:fingerprint'),
    ] as $markup) {
        $buttons = inlineButtonsForTrackingTest($markup);
        $callbacks = array_column($buttons, 'callback_data');

        expect($callbacks)->toContain('post_order:main_menu:7:fingerprint')
            ->and($callbacks)->not->toContain('exit');
    }
});

test('order tracking prompt offers back navigation to main menu', function (): void {
    $buttons = inlineButtonsForTrackingTest(
        (new OrderTrackingKeyboard)->back('7:fingerprint'),
    );

    expect($buttons)->toBe([
        [
            'text' => '⬅️ Назад',
            'callback_data' => 'tracking:back:7:fingerprint',
        ],
    ]);
});
