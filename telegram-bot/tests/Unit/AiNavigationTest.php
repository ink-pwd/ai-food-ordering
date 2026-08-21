<?php

use App\Telegram\Keyboards\AiAssistantKeyboard;
use App\Telegram\Keyboards\MainMenuKeyboard;

/**
 * @return list<array{text: string, callback_data: string|null}>
 */
function inlineButtonsForAiTest(object $keyboard): array
{
    return array_map(
        static fn (array $row): array => [
            'text' => $row[0]->text,
            'callback_data' => $row[0]->callback_data,
        ],
        $keyboard->inline_keyboard,
    );
}

it('places ai assistant before exit in the existing main menu', function (): void {
    $buttons = inlineButtonsForAiTest(
        (new MainMenuKeyboard)->make('7:fingerprint'),
    );

    expect($buttons[4])->toBe([
        'text' => '🤖 AI-помічник',
        'callback_data' => 'menu:ai:7:fingerprint',
    ])->and($buttons[5]['text'])->toBe('🚪 Вийти');
});

it('provides back navigation from ai assistant', function (): void {
    $buttons = inlineButtonsForAiTest(
        (new AiAssistantKeyboard)->back('7:fingerprint'),
    );

    expect($buttons)->toBe([
        [
            'text' => '⬅️ Назад',
            'callback_data' => 'ai:back:7:fingerprint',
        ],
    ]);
});
