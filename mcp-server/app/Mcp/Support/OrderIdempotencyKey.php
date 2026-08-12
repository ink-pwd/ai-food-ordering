<?php

namespace App\Mcp\Support;

use SensitiveParameter;

final class OrderIdempotencyKey
{
    public function forCart(
        #[SensitiveParameter] BackendSessionContext $context,
        int $cartId,
    ): string {
        return hash(
            'sha256',
            'mcp-order:'.$context->backendSessionId().':'.$cartId,
        );
    }
}
