<?php

namespace App\Mcp\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use JsonException;
use SensitiveParameter;

final readonly class SessionContextHandle
{
    /**
     * @var list<string>
     */
    private const array PAYLOAD_KEYS = [
        'version',
        'backend_session_id',
        'backend_session_token',
        'restaurant_slug',
        'expires_at',
    ];

    private const int PAYLOAD_VERSION = 1;

    public function __construct(private Encrypter $encrypter) {}

    public function issue(#[SensitiveParameter] BackendSessionContext $context): string
    {
        $payload = json_encode([
            'version' => self::PAYLOAD_VERSION,
            'backend_session_id' => $context->backendSessionId(),
            'backend_session_token' => $context->backendSessionToken(),
            'restaurant_slug' => $context->restaurantSlug(),
            'expires_at' => $context->expiresAt()->format(DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->encrypter->encrypt($payload, false);
    }

    /**
     * @throws InvalidSessionContextException
     */
    public function restore(#[SensitiveParameter] string $handle): BackendSessionContext
    {
        $payload = $this->decryptPayload($handle);

        if (! $this->hasValidShape($payload)) {
            throw InvalidSessionContextException::malformed();
        }

        $expiresAt = $this->parseExpiration($payload['expires_at']);

        if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw InvalidSessionContextException::expired();
        }

        return new BackendSessionContext(
            backendSessionId: $payload['backend_session_id'],
            backendSessionToken: $payload['backend_session_token'],
            restaurantSlug: $payload['restaurant_slug'],
            expiresAt: $expiresAt,
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidSessionContextException
     */
    private function decryptPayload(#[SensitiveParameter] string $handle): array
    {
        try {
            $decryptedPayload = $this->encrypter->decrypt($handle, false);

            if (! is_string($decryptedPayload)) {
                throw InvalidSessionContextException::malformed();
            }

            $payload = json_decode($decryptedPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw InvalidSessionContextException::malformed();
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw InvalidSessionContextException::malformed();
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasValidShape(array $payload): bool
    {
        $payloadKeys = array_keys($payload);
        sort($payloadKeys);

        $expectedKeys = self::PAYLOAD_KEYS;
        sort($expectedKeys);

        return $payloadKeys === $expectedKeys
            && $payload['version'] === self::PAYLOAD_VERSION
            && $this->isNonEmptyString($payload['backend_session_id'])
            && $this->isNonEmptyString($payload['backend_session_token'])
            && $this->isNonEmptyString($payload['restaurant_slug'])
            && is_string($payload['expires_at']);
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    /**
     * @throws InvalidSessionContextException
     */
    private function parseExpiration(string $expiresAt): CarbonImmutable
    {
        try {
            $expiration = CarbonImmutable::createFromFormat(DateTimeInterface::ATOM, $expiresAt);
        } catch (InvalidFormatException) {
            throw InvalidSessionContextException::malformed();
        }

        if (! $expiration instanceof CarbonImmutable
            || $expiration->format(DateTimeInterface::ATOM) !== $expiresAt) {
            throw InvalidSessionContextException::malformed();
        }

        return $expiration;
    }
}
