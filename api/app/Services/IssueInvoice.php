<?php

namespace App\Services;

use App\Exceptions\InvoiceYearRolled;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues the one invoice of an order. Lock order everywhere: order row, then counter row, then invoice rows.
 * Everything is validated and read first; the number is allocated as late as possible, then the rows are inserted
 * and the transaction commits. A failure anywhere rolls back the counter too, so no number is ever burned.
 */
class IssueInvoice
{
    public function __construct(private readonly InvoiceNumberAllocator $allocator, private readonly InvoiceSnapshotBuilder $snapshots) {}

    public function handle(User $actor, Order $order, ?string $dueDateOverride): Invoice
    {
        // The clock can cross midnight on 31 December between the two reads in allocate(): nothing was consumed, so retry once.
        try {
            return DB::transaction(fn () => $this->issue($actor, $order->id, $dueDateOverride));
        } catch (InvoiceYearRolled) {
            return DB::transaction(fn () => $this->issue($actor, $order->id, $dueDateOverride));
        }
    }

    private function issue(User $actor, int $orderId, ?string $dueDateOverride): Invoice
    {
        $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

        if (! in_array($order->status->value, config('invoicing.eligible_order_statuses'), true)) {
            $this->refuse('order', 'Only confirmed, shipped or delivered orders can be invoiced.');
        }
        if (Invoice::where('order_id', $order->id)->where('status', '<>', 'void')->exists()) {
            $this->refuse('order', 'This order already has an invoice.');
        }
        if ($order->tax_cents !== 0 || $order->shipping_cents !== 0) {
            $this->refuse('order', 'This order has tax or shipping and cannot be invoiced yet.');
        }

        $items = $order->items()->get();
        if ($items->isEmpty()) {
            $this->refuse('order', 'This order has no lines.');
        }
        // A mismatch is a data-integrity bug, not a user error: fail loudly, write nothing.
        if ((int) $items->sum('line_total_cents') !== $order->subtotal_cents) {
            throw new \LogicException("Order {$order->order_number} lines do not add up to its subtotal.");
        }

        $this->snapshots->assertConfigured();
        $buyer = $this->snapshots->buyer(Company::findOrFail($order->company_id));
        $seller = $this->snapshots->seller();
        $rate = config('invoicing.vat_rate_bp');

        $number = $this->allocator->allocate();
        $dueDate = $this->dueDate($number, $dueDateOverride);

        $attributes = $this->snapshots->attributes($order, $items, $number, $dueDate, $rate, $actor->id, $seller, $buyer);

        $invoice = $this->snapshots->write(
            $attributes,
            $this->snapshots->itemRows($items, $attributes['created_at']),
            [['from_status' => null, 'to_status' => 'open', 'actor_user_id' => $actor->id, 'note' => null, 'created_at' => $attributes['created_at']]],
        );

        AuditLog::record(
            'invoice.created', 'invoice', $invoice->public_id, $invoice->number, $actor,
            [], ['status' => 'open', 'total_cents' => $invoice->total_cents, 'due_date' => $dueDate],
            ['invoice_number' => $invoice->number, 'order_number' => $order->order_number], $invoice->company_id,
        );

        return $invoice;
    }

    /** The override was validated against today at request time; re-check it against the real issue date (midnight may have passed). */
    private function dueDate(AllocatedNumber $number, ?string $override): string
    {
        $issue = $number->issueDate;

        if ($override === null) {
            return $issue->addDays(config('invoicing.payment_terms_days'))->toDateString();
        }
        if ($override < $issue->toDateString() || $override > $issue->addDays(config('invoicing.max_due_days'))->toDateString()) {
            $this->refuse('due_date', 'The due date must be between today and '.config('invoicing.max_due_days').' days from today.');
        }

        return $override;
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
