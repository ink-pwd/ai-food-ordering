<?php

namespace App\Integrations\OrderingBackend;

use App\Exceptions\OrderingBackendException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use JsonException;
use Psr\Log\LoggerInterface;

final readonly class OrderingBackendTransport
{
    public function __construct(
        private Factory $http,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(
        string $path,
        string $operation,
        string $message,
        array $query = [],
    ): Response {
        try {
            return $this->request()
                ->get($path, $query)
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->requestException(
                operation: $operation,
                message: $message,
                exception: $exception,
            );
        }
    }

    public function sessionBoundGet(
        string $sessionToken,
        string $path,
        string $operation,
        string $message,
    ): Response {
        try {
            return $this->request($sessionToken)
                ->get($path)
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->requestException(
                operation: $operation,
                message: $message,
                exception: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function sessionBoundPost(
        string $sessionToken,
        string $path,
        string $operation,
        string $message,
        array $data = [],
        array $headers = [],
    ): Response {
        try {
            return $this->request($sessionToken)
                ->withHeaders($headers)
                ->post($path, $data)
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->requestException(
                operation: $operation,
                message: $message,
                exception: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sessionBoundPut(
        string $sessionToken,
        string $path,
        string $operation,
        string $message,
        array $data,
    ): Response {
        try {
            return $this->request($sessionToken)
                ->put($path, $data)
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->requestException(
                operation: $operation,
                message: $message,
                exception: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sessionBoundPatch(
        string $sessionToken,
        string $path,
        string $operation,
        string $message,
        array $data,
    ): Response {
        try {
            return $this->request($sessionToken)
                ->patch($path, $data)
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->requestException(
                operation: $operation,
                message: $message,
                exception: $exception,
            );
        }
    }

    public function sessionBoundDelete(
        string $sessionToken,
        string $path,
        string $operation,
        string $message,
    ): Response {
        try {
            return $this->request($sessionToken)
                ->delete($path)
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->requestException(
                operation: $operation,
                message: $message,
                exception: $exception,
            );
        }
    }

    public function request(
        ?string $sessionToken = null,
    ): PendingRequest {
        /** @var string $baseUrl */
        $baseUrl = config('services.ordering_backend.url');
        /** @var int|string $timeout */
        $timeout = config('services.ordering_backend.timeout');
        /** @var string $internalApiToken */
        $internalApiToken = config('services.ordering_backend.token');

        $request = $this->http
            ->baseUrl((string) $baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout((int) $timeout)
            ->withHeaders([
                'X-Internal-Api-Token' => (string) $internalApiToken,
            ]);

        if ($sessionToken !== null) {
            $request->withHeaders([
                'X-Session-Token' => $sessionToken,
            ]);
        }

        return $request;
    }

    public function requestException(
        string $operation,
        string $message,
        ConnectionException|RequestException $exception,
    ): OrderingBackendException {
        $statusCode = $exception instanceof RequestException
            ? $exception->response->status()
            : null;

        $this->logger->error(
            'Ordering backend request failed.',
            [
                'operation' => $operation,
                'status' => $statusCode,
                'exception' => $exception::class,
            ],
        );

        return new OrderingBackendException(
            message: $message,
            statusCode: $statusCode,
            responseMessage:
            $this->responseMessageFromException(
                $exception,
            ),
            responseErrors:
            $this->responseErrorsFromException(
                $exception,
            ),
            previous: $exception,
        );
    }

    private function responseMessageFromException(
        ConnectionException|RequestException $exception,
    ): ?string {
        if (! $exception instanceof RequestException) {
            return null;
        }

        try {
            $responseMessage =
                $exception->response->json('message');
        } catch (JsonException) {
            return null;
        }

        if (
            ! is_string($responseMessage)
            || trim($responseMessage) === ''
        ) {
            return null;
        }

        return trim($responseMessage);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function responseErrorsFromException(
        ConnectionException|RequestException $exception,
    ): ?array {
        if (! $exception instanceof RequestException) {
            return null;
        }

        try {
            $responseErrors =
                $exception->response->json('errors');
        } catch (JsonException) {
            return null;
        }

        if (! is_array($responseErrors)) {
            return null;
        }

        /** @var array<string, mixed> $responseErrors */
        return $responseErrors;
    }

    public function responseData(
        Response $response,
        string $operation,
        string $invalidMessage =
        'Ordering backend returned malformed catalog data.',
    ): mixed {
        try {
            return $response->json('data');
        } catch (JsonException $exception) {
            throw $this->invalidResponse(
                response: $response,
                operation: $operation,
                message: $invalidMessage,
                previous: $exception,
            );
        }
    }

    public function invalidResponse(
        Response $response,
        string $operation,
        string $message =
        'Ordering backend returned malformed catalog data.',
        ?JsonException $previous = null,
    ): OrderingBackendException {
        $this->logger->error(
            'Ordering backend returned an invalid response.',
            [
                'operation' => $operation,
                'status' => $response->status(),
                'exception' => $previous === null
                    ? null
                    : $previous::class,
            ],
        );

        return new OrderingBackendException(
            message: $message,
            statusCode: $response->status(),
            previous: $previous,
        );
    }

    public function isPositiveInteger(
        mixed $value,
    ): bool {
        return is_int($value) && $value > 0;
    }

    public function isOptionalPositiveInteger(
        mixed $value,
    ): bool {
        return $value === null
            || $this->isPositiveInteger($value);
    }

    public function isNonNegativeInteger(
        mixed $value,
    ): bool {
        return is_int($value) && $value >= 0;
    }

    public function isOptionalInteger(
        mixed $value,
    ): bool {
        return $value === null || is_int($value);
    }

    public function isNonEmptyString(
        mixed $value,
    ): bool {
        return is_string($value)
            && trim($value) !== '';
    }

    public function isOptionalString(
        mixed $value,
    ): bool {
        return $value === null || is_string($value);
    }

    public function isOptionalNonEmptyString(
        mixed $value,
    ): bool {
        return $value === null
            || $this->isNonEmptyString($value);
    }

    public function isIntegerList(
        mixed $value,
    ): bool {
        return is_array($value)
            && array_is_list($value)
            && array_all(
                $value,
                fn (mixed $item): bool => is_int($item),
            );
    }
}
