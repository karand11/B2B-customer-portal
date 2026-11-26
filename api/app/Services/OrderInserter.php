<?php

namespace App\Services;

use App\Models\Order;
use App\Support\OrderNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Inserts an order row with a fresh random number. Shared by PlaceOrder (cart) and AcceptQuote. */
class OrderInserter
{
    /**
     * Retry with a fresh random number on a (practically impossible) number collision; the savepoint keeps the outer
     * transaction usable. A collision on any OTHER unique key (orders.quote_request_id) is not a number clash and is rethrown.
     *
     * @param  array<string, mixed>  $attributes  everything except order_number
     */
    public function insert(array $attributes): Order
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes) {
                    $order = (new Order)->forceFill($attributes + ['order_number' => app(OrderNumber::class)->generate()]);
                    $order->save();

                    return $order;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5 || ! str_contains($e->getMessage(), 'orders_order_number_unique')) {
                    throw $e;
                }
            }
        }
    }
}
