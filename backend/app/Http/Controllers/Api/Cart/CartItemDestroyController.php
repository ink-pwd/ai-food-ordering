<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\MutateCartRequest;
use App\Http\Responses\CartResponse;
use App\Services\Handlers\Cart\RemoveCartItemHandler;

class CartItemDestroyController extends Controller
{
    public function __invoke(
        MutateCartRequest $request,
        RemoveCartItemHandler $handler,
        int $item,
    ): CartResponse {
        $cart = $handler->handle(
            $request->internalSession(),
            $item,
        );

        return new CartResponse($cart);
    }
}
