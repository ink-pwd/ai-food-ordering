<?php

use App\Integrations\OrderingBackend\OrderingBackendTransport;

function transportValidationInstance(): OrderingBackendTransport
{
    /** @var OrderingBackendTransport $transport */
    $transport = (new ReflectionClass(OrderingBackendTransport::class))->newInstanceWithoutConstructor();

    return $transport;
}

test('ordering backend transport validates primitive response values strictly', function (string $method, mixed $value, bool $expected): void {
    $transport = transportValidationInstance();

    expect($transport->{$method}($value))->toBe($expected);
})->with([
    'positive one' => ['isPositiveInteger', 1, true],
    'positive large' => ['isPositiveInteger', 99, true],
    'positive zero rejected' => ['isPositiveInteger', 0, false],
    'positive negative rejected' => ['isPositiveInteger', -1, false],
    'positive numeric string rejected' => ['isPositiveInteger', '1', false],

    'optional positive null' => ['isOptionalPositiveInteger', null, true],
    'optional positive one' => ['isOptionalPositiveInteger', 1, true],
    'optional positive zero rejected' => ['isOptionalPositiveInteger', 0, false],
    'optional positive string rejected' => ['isOptionalPositiveInteger', '1', false],

    'non negative zero' => ['isNonNegativeInteger', 0, true],
    'non negative positive' => ['isNonNegativeInteger', 3, true],
    'non negative negative rejected' => ['isNonNegativeInteger', -1, false],
    'non negative string rejected' => ['isNonNegativeInteger', '0', false],

    'optional integer null' => ['isOptionalInteger', null, true],
    'optional integer zero' => ['isOptionalInteger', 0, true],
    'optional integer negative' => ['isOptionalInteger', -5, true],
    'optional integer string rejected' => ['isOptionalInteger', '5', false],

    'non empty string' => ['isNonEmptyString', 'value', true],
    'non empty padded string' => ['isNonEmptyString', ' value ', true],
    'empty string rejected' => ['isNonEmptyString', '', false],
    'blank string rejected' => ['isNonEmptyString', '   ', false],

    'integer empty list' => ['isIntegerList', [], true],
    'integer list' => ['isIntegerList', [1, 2, 3], true],
    'integer string list rejected' => ['isIntegerList', ['1', '2'], false],
]);
