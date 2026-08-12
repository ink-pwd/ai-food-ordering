<?php

namespace App\Integrations\OrderingBackend;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use JsonException;
use SensitiveParameter;

class OrderingBackendClient
{
    public function __construct(private Factory $http) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     */
    public function get(
        string $path,
        array $query = [],
        #[SensitiveParameter] ?string $sessionToken = null,
    ): array {
        return $this->send(
            method: 'GET',
            path: $path,
            query: $query,
            sessionToken: $sessionToken,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function post(
        string $path,
        #[SensitiveParameter] array $payload = [],
        #[SensitiveParameter] ?string $sessionToken = null,
        #[SensitiveParameter] ?string $idempotencyKey = null,
    ): array {
        return $this->send(
            method: 'POST',
            path: $path,
            payload: $payload,
            sessionToken: $sessionToken,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function put(
        string $path,
        #[SensitiveParameter] array $payload = [],
        #[SensitiveParameter] ?string $sessionToken = null,
    ): array {
        return $this->send(
            method: 'PUT',
            path: $path,
            payload: $payload,
            sessionToken: $sessionToken,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function patch(
        string $path,
        #[SensitiveParameter] array $payload = [],
        #[SensitiveParameter] ?string $sessionToken = null,
    ): array {
        return $this->send(
            method: 'PATCH',
            path: $path,
            payload: $payload,
            sessionToken: $sessionToken,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function delete(
        string $path,
        #[SensitiveParameter] array $payload = [],
        #[SensitiveParameter] ?string $sessionToken = null,
    ): array {
        return $this->send(
            method: 'DELETE',
            path: $path,
            payload: $payload,
            sessionToken: $sessionToken,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function send(
        string $method,
        string $path,
        array $query = [],
        #[SensitiveParameter] array $payload = [],
        #[SensitiveParameter] ?string $sessionToken = null,
        #[SensitiveParameter] ?string $idempotencyKey = null,
    ): array {
        try {
            $response = $this->request($sessionToken, $idempotencyKey)->send(
                $method,
                $path,
                $method === 'GET' ? ['query' => $query] : ['json' => $payload],
            );
        } catch (ConnectionException) {
            throw OrderingBackendException::connectionFailure();
        }

        if (! $response->successful()) {
            throw OrderingBackendException::forBackendStatus($response->status());
        }

        return $this->parseJson($response);
    }

    private function request(
        #[SensitiveParameter] ?string $sessionToken,
        #[SensitiveParameter] ?string $idempotencyKey,
    ): PendingRequest {
        /** @var array{url: string, internal_api_token: string|null, timeout: int|float} $configuration */
        $configuration = config('services.ordering_backend');

        $headers = [
            'X-Internal-Api-Token' => (string) $configuration['internal_api_token'],
        ];

        if ($sessionToken !== null) {
            $headers['X-Session-Token'] = $sessionToken;
        }

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->http
            ->baseUrl($configuration['url'])
            ->acceptJson()
            ->asJson()
            ->withHeaders($headers)
            ->timeout($configuration['timeout']);
    }

    /** @return array<array-key, mixed> */
    private function parseJson(Response $response): array
    {
        try {
            $payload = $response->json(flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw OrderingBackendException::invalidResponse($response->status());
        }

        if (! is_array($payload)) {
            throw OrderingBackendException::invalidResponse($response->status());
        }

        return $payload;
    }
}
