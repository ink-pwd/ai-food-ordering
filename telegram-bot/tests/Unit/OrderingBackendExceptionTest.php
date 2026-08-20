<?php

use App\Exceptions\OrderingBackendException;

test('ordering backend exception exposes safe response metadata', function (): void {
    $exception = new OrderingBackendException(
        message: 'Unable to update cart.',
        statusCode: 422,
        responseMessage: 'Validation failed.',
        responseErrors: ['quantity' => ['Invalid quantity.']],
    );

    expect($exception->getMessage())->toBe('Unable to update cart.')
        ->and($exception->statusCode())->toBe(422)
        ->and($exception->responseMessage())->toBe('Validation failed.')
        ->and($exception->responseErrors())->toBe(['quantity' => ['Invalid quantity.']]);
});
