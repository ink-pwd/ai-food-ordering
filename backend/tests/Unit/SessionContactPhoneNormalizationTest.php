<?php

use App\Http\Requests\UpdateSessionContactRequest;

function normalizeBackendPhoneForTest(string $phone): string
{
    $request = new UpdateSessionContactRequest;
    $method = new ReflectionMethod($request, 'normalizePhone');
    $result = $method->invoke($request, $phone);

    if (! is_string($result)) {
        throw new RuntimeException('normalizePhone must return a string.');
    }

    return $result;
}

function isValidBackendPhoneForTest(string $phone): bool
{
    $request = new UpdateSessionContactRequest;
    $method = new ReflectionMethod($request, 'isValidNormalizedPhone');
    $result = $method->invoke($request, $phone);

    if (! is_bool($result)) {
        throw new RuntimeException('isValidNormalizedPhone must return a bool.');
    }

    return $result;
}

test('contact phone normalization accepts required international formats', function (string $phone, string $expected): void {
    $normalized = normalizeBackendPhoneForTest($phone);

    expect($normalized)->toBe($expected)
        ->and(isValidBackendPhoneForTest($normalized))->toBeTrue();
})->with([
    'ukraine plus' => ['+380931234567', '+380931234567'],
    'spain plus' => ['+34123456789', '+34123456789'],
    'usa plus' => ['+14155552671', '+14155552671'],
    'ukraine no plus' => ['380931234567', '+380931234567'],
    'spain no plus' => ['34123456789', '+34123456789'],
    'usa no plus' => ['14155552671', '+14155552671'],
    'ukraine 00 prefix' => ['00380931234567', '+380931234567'],
    'spain 00 prefix' => ['0034123456789', '+34123456789'],
    'usa 00 prefix' => ['0014155552671', '+14155552671'],
    'ukraine local' => ['0931234567', '+380931234567'],
    'ukraine formatted' => ['+380 (93) 123-45-67', '+380931234567'],
    'spain formatted' => ['+34 612 34 56 78', '+34612345678'],
    'usa formatted' => ['+1 (415) 555-2671', '+14155552671'],
]);

test('contact phone validation rejects malformed normalized numbers', function (string $phone): void {
    $normalized = normalizeBackendPhoneForTest($phone);

    expect(isValidBackendPhoneForTest($normalized))->toBeFalse();
})->with([
    'too short plus' => ['+34123'],
    'ambiguous local' => ['5552671'],
    'letters' => ['+1ABC5552671'],
    'misplaced plus' => ['1415+5552671'],
]);
