<?php

namespace App\Services\Cart;

use App\DTO\SessionData;
use App\Models\Cart;
use App\Services\Handlers\Cart\ClearCartHandler;

readonly class ClearCartService
{
    public function __construct(
        private ClearCartHandler $clearCartHandler,
    ) {
    }

    public function handle(SessionData $session): Cart
    {
        return $this->clearCartHandler->handle($session);
    }
}
