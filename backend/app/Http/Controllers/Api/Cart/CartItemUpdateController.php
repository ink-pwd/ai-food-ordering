<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Responses\CartResponse;
use App\Services\Handlers\Cart\UpdateCartItemHandler;

class CartItemUpdateController extends Controller
{
    public function __invoke(
        UpdateCartItemRequest $request,
        UpdateCartItemHandler $updateCartItemHandler,
        int $itemId,
    ): CartResponse {
        $cart = $updateCartItemHandler->handle(
            $request->internalSession(),
            $itemId,
            $request->quantity(),
        );

        return new CartResponse($cart);
    }
}
