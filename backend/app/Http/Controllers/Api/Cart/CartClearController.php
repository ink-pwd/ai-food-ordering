<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\MutateCartRequest;
use App\Http\Responses\CartResponse;
use App\Services\Handlers\Cart\ClearCartHandler;

class CartClearController extends Controller
{
    public function __invoke(
        MutateCartRequest $request,
        ClearCartHandler $handler,
    ): CartResponse {
        $cart = $handler->handle(
            $request->internalSession(),
        );

        return new CartResponse($cart);
    }
}
