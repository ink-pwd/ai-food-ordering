<?php

namespace App\Integrations\Dots;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class DotsClient
{
    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int REQUEST_TIMEOUT_SECONDS = 10;

    private const int RETRY_ATTEMPTS = 3;

    private const int RETRY_SLEEP_MILLISECONDS = 100;

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function get(string $path, array $query = []): array
    {
        $response = $this->request()
            ->retry(
                self::RETRY_ATTEMPTS,
                self::RETRY_SLEEP_MILLISECONDS,
                fn (mixed $exception, PendingRequest $request, ?string $method): bool => $method === 'GET'
                    && $this->isTransientFailure($exception),
            )
            ->get($path, array_merge($query, ['v' => config('services.dots.api_version')]))
            ->throw();

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function authenticatedGet(string $path, array $query = []): array
    {
        $response = $this->request()
            ->withHeader(
                'Api-Auth-Token',
                config('services.dots.auth_token'),
            )
            ->retry(
                self::RETRY_ATTEMPTS,
                self::RETRY_SLEEP_MILLISECONDS,
                fn (
                    mixed $exception,
                    PendingRequest $request,
                    ?string $method,
                ): bool => $method === 'GET'
                    && $this->isTransientFailure($exception),
            )
            ->get(
                $path,
                array_merge(
                    $query,
                    ['v' => config('services.dots.api_version')],
                ),
            )
            ->throw();

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function post(string $path, array $payload = []): array
    {
        $response = $this->request()
            ->withQueryParameters([
                'v' => config('services.dots.api_version'),
            ])
            ->post($path, $payload)
            ->throw();

        return $response->json();
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function authenticatedPost(string $path, array $payload = []): array
    {
        $response = $this->request()
            ->withHeader(
                'Api-Auth-Token',
                config('services.dots.auth_token'),
            )
            ->withQueryParameters([
                'v' => config('services.dots.api_version'),
            ])
            ->post($path, $payload)
            ->throw();

        return $response->json();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('services.dots.base_url'))
            ->acceptJson()
            ->withHeaders([
                'Api-Token' => config('services.dots.token'),
                'Api-Account-Token' => config('services.dots.account_token'),
                'Api-lang' => 'ua',
            ])
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS);
    }

    private function isTransientFailure(mixed $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $response = $exception->response;

        return $response->status() === 429 || $response->serverError();
    }
}
