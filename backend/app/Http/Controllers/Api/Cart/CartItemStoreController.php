<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddCartItemRequest;
use App\Http\Responses\CartResponse;
use App\Services\Handlers\Cart\AddCartItemHandler;
use Symfony\Component\HttpFoundation\Response;

class CartItemStoreController extends Controller
{
    public function __invoke(
        AddCartItemRequest $request,
        AddCartItemHandler $addCartItem,
    ): CartResponse {
        $cart = $addCartItem->handle(
            $request->internalSession(),
            $request->productId(),
            $request->quantity(),
        );

        return new CartResponse(
            $cart,
            false,
            Response::HTTP_CREATED,
        );
    }
}
