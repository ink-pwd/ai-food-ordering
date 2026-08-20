<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\MutateCartRequest;
use App\Http\Responses\CartResponse;
use App\Services\Cart\ClearCartService;

class CartClearController extends Controller
{
    public function __invoke(
        MutateCartRequest $request,
        ClearCartService $clearCartService,
    ): CartResponse {
        $cart = $clearCartService->handle(
            $request->internalSession(),
        );

        return new CartResponse($cart);
    }
}
