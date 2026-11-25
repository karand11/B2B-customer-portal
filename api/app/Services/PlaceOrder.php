<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Support\OrderNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Builds an order from the user's cart. The client supplies nothing but an optional PO reference. */
class PlaceOrder
{
    public function __construct(private CartService $cart) {}

    public function handle(User $user, ?string $poReference): Order
    {
        return $this->cart->locked($user, function () use ($user, $poReference) {
            // A concurrent double submit waits on the user lock, then finds the cart empty here.
            $lines = $this->cart->items($user);

            $problems = $lines->isEmpty() ? ['Your cart is empty.'] : [];
            foreach ($lines as $line) {
                $text = match ($line->product->unavailableReason()) {
                    'inactive' => 'is no longer available.',
                    'price_on_request' => 'is price on request.',
                    'out_of_stock' => 'is out of stock.',
                    default => null,
                };
                $text === null || $problems[] = "{$line->product->part_number} {$text}";
            }
            if ($problems) {
                throw ValidationException::withMessages(['cart' => $problems]);
            }

            $now = now();
            $rows = $lines->map(fn (CartItem $line) => [
                'product_id' => $line->product_id,
                'part_number' => $line->product->part_number,
                'name' => $line->product->name,
                'brand' => $line->product->brand,
                'unit_price_cents' => $line->product->price_cents,
                'quantity' => $line->quantity,
                'line_total_cents' => $line->product->price_cents * $line->quantity,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $subtotal = $rows->sum('line_total_cents');

            $order = $this->insertOrder([
                'company_id' => $user->company_id,
                'created_by_user_id' => $user->id,
                'status' => OrderStatus::Pending,
                'currency' => config('catalog.currency'),
                'po_reference' => $poReference,
                'subtotal_cents' => $subtotal,
                'tax_cents' => 0,
                'shipping_cents' => 0,
                'total_cents' => $subtotal,
            ]);

            OrderItem::insert($rows->map(fn (array $row) => $row + ['order_id' => $order->id])->all());
            (new OrderStatusHistory)->forceFill([
                'order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::Pending, 'actor_user_id' => $user->id,
            ])->save();
            CartItem::where('user_id', $user->id)->delete();

            return $order;
        });
    }

    /** Retry with a fresh random number on a (practically impossible) collision; the savepoint keeps the transaction usable. */
    private function insertOrder(array $attributes): Order
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes) {
                    $order = (new Order)->forceFill($attributes + ['order_number' => app(OrderNumber::class)->generate()]);
                    $order->save();

                    return $order;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }
}
