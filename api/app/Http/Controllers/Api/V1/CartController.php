<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddCartItemRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Services\CartService;
use Illuminate\Http\Request;

/** Role middleware (customer) guards the group; every query is scoped to the session user. */
class CartController extends Controller
{
    public function __construct(private CartService $cart) {}

    public function show(Request $request): CartResource
    {
        return new CartResource($this->cart->items($request->user()));
    }

    public function add(AddCartItemRequest $request): CartResource
    {
        return new CartResource($this->cart->add($request->user(), $request->integer('product_id'), $request->integer('quantity')));
    }

    public function update(UpdateCartItemRequest $request, string $productId): CartResource
    {
        $items = $this->cart->setQuantity($request->user(), (int) $productId, $request->integer('quantity'));

        return new CartResource($items ?? abort(404));
    }

    public function remove(Request $request, string $productId): CartResource
    {
        return new CartResource($this->cart->remove($request->user(), (int) $productId) ?? abort(404));
    }

    public function clear(Request $request): CartResource
    {
        $this->cart->clear($request->user());

        return new CartResource($this->cart->items($request->user()));
    }
}
