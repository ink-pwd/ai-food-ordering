<?php

use App\Exceptions\OrderingBackendException;

test('ordering backend exception keeps optional response metadata nullable', function (): void {
    $exception = new OrderingBackendException('Failure');

    expect($exception->getMessage())->toBe('Failure')
        ->and($exception->statusCode())->toBeNull()
        ->and($exception->responseMessage())->toBeNull()
        ->and($exception->responseErrors())->toBeNull();
});

test('ordering backend exception keeps previous exception chain', function (): void {
    $previous = new RuntimeException('transport');
    $exception = new OrderingBackendException('Failure', previous: $previous);

    expect($exception->getPrevious())->toBe($previous);
});
