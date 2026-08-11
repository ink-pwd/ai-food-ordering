<?php

namespace App\Exceptions;

use Exception;
use Throwable;

final class OrderingBackendException extends Exception
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly ?string $responseMessage = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function responseMessage(): ?string
    {
        return $this->responseMessage;
    }
}
