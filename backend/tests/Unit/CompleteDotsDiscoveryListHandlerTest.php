<?php

use App\Services\Handlers\Synchronization\CompleteDotsDiscoveryListHandler;

test('complete discovery handler returns complete list payloads without reshaping items', function (array $response, string $field, array $expected): void {
    expect((new CompleteDotsDiscoveryListHandler)->handle($response, $field))->toBe($expected);
})->with([
    'direct empty list' => [[], 'cities', []],
    'direct city list' => [[['id' => 'city-1']], 'cities', [['id' => 'city-1']]],
    'named cities list' => [['hasNext' => false, 'cities' => [['id' => 'city-1']]], 'cities', [['id' => 'city-1']]],
    'named companies list' => [['hasNext' => false, 'companies' => [['id' => 'company-1']]], 'companies', [['id' => 'company-1']]],
    'items fallback' => [['hasNext' => false, 'items' => [['id' => 'fallback-1']]], 'companies', [['id' => 'fallback-1']]],
    'explicit field wins over items' => [[
        'hasNext' => false,
        'cities' => [['id' => 'city-1']],
        'items' => [['id' => 'fallback-1']],
    ], 'cities', [['id' => 'city-1']]],
]);
