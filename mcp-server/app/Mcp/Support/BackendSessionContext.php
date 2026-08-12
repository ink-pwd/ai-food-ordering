<?php

namespace App\Mcp\Support;

use Carbon\CarbonImmutable;
use SensitiveParameter;

final readonly class BackendSessionContext
{
    public function __construct(
        private string $backendSessionId,
        #[SensitiveParameter]
        private string $backendSessionToken,
        private string $restaurantSlug,
        private CarbonImmutable $expiresAt,
    ) {}

    public function backendSessionId(): string
    {
        return $this->backendSessionId;
    }

    public function backendSessionToken(): string
    {
        return $this->backendSessionToken;
    }

    public function restaurantSlug(): string
    {
        return $this->restaurantSlug;
    }

    public function expiresAt(): CarbonImmutable
    {
        return $this->expiresAt;
    }
}
