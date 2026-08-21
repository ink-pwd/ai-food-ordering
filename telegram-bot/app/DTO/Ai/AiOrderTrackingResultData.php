<?php

namespace App\DTO\Ai;

final readonly class AiOrderTrackingResultData
{
    public function __construct(
        public int $orderId,
        public bool $found,
        public ?string $summary,
    ) {
    }

    public static function missing(int $orderId): self
    {
        return new self(
            orderId: $orderId,
            found: false,
            summary: null,
        );
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'found' => $this->found,
            'summary' => $this->summary,
        ];
    }
}
