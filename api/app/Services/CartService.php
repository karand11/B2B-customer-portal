<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Every cart write, reorder and checkout runs under the same per-user row lock (see locked()). */
class CartService
{
    /** @return Collection<int, CartItem> the user's lines with live products, first added first */
    public function items(User $user): Collection
    {
        return CartItem::where('user_id', $user->id)->with('product')->orderBy('id')->get();
    }

    /**
     * Run $fn in a transaction holding a lock on the user's row. This serialises one user's concurrent
     * cart actions and checkouts (also when the cart is empty), so the caps below are race-free.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public function locked(User $user, Closure $fn): mixed
    {
        return DB::transaction(function () use ($user, $fn) {
            User::whereKey($user->id)->lockForUpdate()->first();

            return $fn();
        });
    }

    public function add(User $user, int $productId, int $quantity): Collection
    {
        $this->locked($user, function () use ($user, $productId, $quantity) {
            $product = Product::active()->find($productId)
                ?? throw ValidationException::withMessages(['product_id' => 'The selected product is not available.']);

            match ($product->unavailableReason()) {
                'price_on_request' => throw ValidationException::withMessages(['product_id' => 'This product is price on request and cannot be added to the cart.']),
                'out_of_stock' => throw ValidationException::withMessages(['product_id' => 'This product is out of stock.']),
                default => null,
            };

            $line = CartItem::where('user_id', $user->id)->where('product_id', $product->id)->first();

            if ($line === null) {
                if (CartItem::where('user_id', $user->id)->count() >= config('orders.max_cart_lines')) {
                    throw ValidationException::withMessages(['product_id' => 'Your cart is full.']);
                }
                $line = (new CartItem)->forceFill(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 0]);
            }

            $total = $line->quantity + $quantity;
            if ($total > config('orders.max_quantity')) {
                throw ValidationException::withMessages(['quantity' => 'The quantity in the cart cannot exceed '.config('orders.max_quantity').'.']);
            }

            $line->forceFill(['quantity' => $total])->save();
        });

        return $this->items($user);
    }

    /** Absolute quantity; availability is deliberately not re-checked here (checkout does). Null when the user has no such line. */
    public function setQuantity(User $user, int $productId, int $quantity): ?Collection
    {
        $updated = $this->locked($user, fn () => CartItem::where('user_id', $user->id)->where('product_id', $productId)
            ->update(['quantity' => $quantity]));

        return $updated ? $this->items($user) : null;
    }

    /** Null when the user has no such line. */
    public function remove(User $user, int $productId): ?Collection
    {
        $deleted = $this->locked($user, fn () => CartItem::where('user_id', $user->id)->where('product_id', $productId)->delete());

        return $deleted ? $this->items($user) : null;
    }

    public function clear(User $user): void
    {
        $this->locked($user, fn () => CartItem::where('user_id', $user->id)->delete());
    }

    /**
     * Copy an order's lines into the user's cart at current prices.
     *
     * @return array{added: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function reorder(User $user, Order $order): array
    {
        return $this->locked($user, function () use ($user, $order) {
            $products = Product::whereIn('id', $order->items->pluck('product_id')->filter())->get()->keyBy('id');
            $cart = CartItem::where('user_id', $user->id)->get()->keyBy('product_id');
            $added = $skipped = [];

            foreach ($order->items as $item) {
                $line = $item->product_id === null ? null : $cart->get($item->product_id);
                // A line created from a custom quote line has no catalog product to re-order.
                $reason = $item->product_id === null ? 'not_in_catalog' : ($products[$item->product_id]->unavailableReason()
                    ?? (match (true) {
                        $line === null && $cart->count() >= config('orders.max_cart_lines') => 'cart_full',
                        ($line?->quantity ?? 0) + $item->quantity > config('orders.max_quantity') => 'quantity_limit',
                        default => null,
                    }));

                if ($reason !== null) {
                    $skipped[] = ['product_id' => $item->product_id, 'part_number' => $item->part_number, 'name' => $item->name, 'reason' => $reason];

                    continue;
                }

                $line ??= (new CartItem)->forceFill(['user_id' => $user->id, 'product_id' => $item->product_id, 'quantity' => 0]);
                $line->forceFill(['quantity' => $line->quantity + $item->quantity])->save();
                $cart->put($item->product_id, $line);
                $added[] = ['product_id' => $item->product_id, 'part_number' => $item->part_number, 'quantity' => $item->quantity];
            }

            return ['added' => $added, 'skipped' => $skipped];
        });
    }
}
