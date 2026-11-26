<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\QuoteItem;
use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Customer accepts a sent quote: one transaction under the quote row lock creates exactly one order from the quote's
 * snapshot lines and the staff-agreed prices (never the live catalog) and marks the quote accepted. The UNIQUE
 * orders.quote_request_id is the database-level backstop for exactly-once.
 */
class AcceptQuote
{
    public function __construct(private QuoteWorkflow $workflow, private OrderInserter $orders) {}

    public function handle(QuoteRequest $quote, User $user, int $revision, ?string $poReference): QuoteRequest
    {
        return $this->workflow->run($quote, 'accept', $user, function (QuoteRequest $locked) use ($user, $revision, $poReference) {
            if ($locked->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => 'This quote was updated. Please review the new prices.']);
            }

            $order = $this->orders->insert([
                'company_id' => $locked->company_id,
                'created_by_user_id' => $user->id,
                'status' => OrderStatus::Pending,
                'currency' => $locked->currency,
                'po_reference' => $poReference ?? $locked->po_reference,
                'subtotal_cents' => $locked->subtotal_cents,
                'tax_cents' => $locked->tax_cents,
                'shipping_cents' => $locked->shipping_cents,
                'total_cents' => $locked->total_cents,
                'quote_request_id' => $locked->id,
            ]);

            $now = now();
            OrderItem::insert($locked->items()->get()->map(fn (QuoteItem $item) => [
                'order_id' => $order->id,
                'product_id' => $item->product_id,
                'part_number' => $item->part_number,
                'name' => $item->name,
                'brand' => $item->brand,
                'unit_price_cents' => $item->unit_price_cents,
                'quantity' => $item->quantity,
                'line_total_cents' => $item->line_total_cents,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            (new OrderStatusHistory)->forceFill([
                'order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::Pending, 'actor_user_id' => $user->id,
            ])->save();
        });
    }
}
