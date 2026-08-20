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
        RemoveCartItemHandler $removeCartItemHandler,
        int $itemId,
    ): CartResponse {
        $cart = $removeCartItemHandler->handle(
            $request->internalSession(),
            $itemId,
        );

        return new CartResponse($cart);
    }
}
