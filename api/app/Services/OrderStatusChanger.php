<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The only place that changes orders.status: locks the row, checks the matrix, writes exactly one history row. */
class OrderStatusChanger
{
    public function change(Order $order, OrderStatus $to, User $actor): Order
    {
        return DB::transaction(function () use ($order, $to, $actor) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! in_array($to, $from->allowedFor($actor), true)) {
                throw ValidationException::withMessages(['status' => $to === OrderStatus::Cancelled
                    ? 'This order can no longer be cancelled.'
                    : "Cannot change status from {$from->value} to {$to->value}."]);
            }

            // Lock order is order row first, then invoice rows (IssueInvoice does the same), so issuing and cancelling serialise here.
            if ($to === OrderStatus::Cancelled && Invoice::where('order_id', $locked->id)->where('status', '<>', 'void')->exists()) {
                throw ValidationException::withMessages(['status' => 'This order has an invoice. Void the invoice first.']);
            }

            $locked->forceFill(['status' => $to])->save();
            (new OrderStatusHistory)->forceFill([
                'order_id' => $locked->id, 'from_status' => $from, 'to_status' => $to, 'actor_user_id' => $actor->id,
            ])->save();

            return $locked;
        });
    }
}
