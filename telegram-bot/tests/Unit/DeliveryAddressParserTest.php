<?php

use App\Telegram\Support\DeliveryAddressParser;

test('delivery address parser extracts stable comma separated address parts', function (string $text, int $type, ?array $expected): void {
    $result = (new DeliveryAddressParser)->parse($text, $type);

    if ($expected === null) {
        expect($result)->toBeNull();

        return;
    }

    expect($result)->not->toBeNull()
        ->and($result->toArray())->toBe($expected);
})->with([
    'street and house' => ['Main Street, 10', 1, ['type' => 1, 'street' => 'Main Street', 'house' => '10']],
    'trim spaces' => ['  Main Street  ,  10  ', 1, ['type' => 1, 'street' => 'Main Street', 'house' => '10']],
    'with flat' => ['Main Street,10,12', 1, ['type' => 1, 'street' => 'Main Street', 'house' => '10', 'flat' => '12']],
    'flat trimmed' => ['Main Street, 10,  12 ', 0, ['type' => 0, 'street' => 'Main Street', 'house' => '10', 'flat' => '12']],
    'empty segment removed' => ['Main Street,,10', 1, ['type' => 1, 'street' => 'Main Street', 'house' => '10']],
    'leading empty segment removed' => [', Main Street, 10', 2, ['type' => 2, 'street' => 'Main Street', 'house' => '10']],
    'extra parts ignored after flat' => ['Main Street,10,12,Gate', 1, ['type' => 1, 'street' => 'Main Street', 'house' => '10', 'flat' => '12']],
    'numeric looking parts stay strings' => ['1,2,3', 1, ['type' => 1, 'street' => '1', 'house' => '2', 'flat' => '3']],
    'single part invalid' => ['Main Street', 1, null],
    'empty invalid' => ['', 1, null],
    'only commas invalid' => [',,,', 1, null],
    'whitespace parts invalid' => ['   ,   ', 1, null],
]);
