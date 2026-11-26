<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that changes invoices.status. Each method locks the row, re-reads it, checks the transition on the
 * locked row, writes exactly one history row and returns the fresh model. A refusal is a 422 on `status`.
 * The history `from_status` is the STORED status (no synthetic overdue row is invented for an effective overdue).
 */
class InvoiceWorkflow
{
    /** @param  string|null  $paidOn  YYYY-MM-DD already checked against today; the lower bound (issue date) is checked here under the lock */
    public function markPaid(Invoice $invoice, User $actor, ?string $paidOn): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor, $paidOn) {
            $locked = $this->lock($invoice->id);
            $this->assertSettleable($locked, 'Only open or overdue invoices can be marked as paid.', 'This invoice is already paid.');

            $paidOn ??= Invoice::today();
            if ($paidOn < $locked->issue_date->toDateString()) {
                throw ValidationException::withMessages(['paid_on' => 'The payment date cannot be before the invoice issue date.']);
            }

            return $this->change($locked, InvoiceStatus::Paid, $actor, null, ['paid_on' => $paidOn]);
        });
    }

    public function void(Invoice $invoice, User $actor, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor, $reason) {
            $locked = $this->lock($invoice->id);
            $this->assertSettleable($locked, 'Only open or overdue invoices can be voided.');

            return $this->change($locked, InvoiceStatus::Void, $actor, $reason);
        });
    }

    /** Materialise an overdue (invoices:mark-overdue). False when the invoice is no longer stored-open-and-past-due once locked. */
    public function markOverdue(int $invoiceId): bool
    {
        return DB::transaction(function () use ($invoiceId) {
            $locked = Invoice::whereKey($invoiceId)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== InvoiceStatus::Open || $locked->effectiveStatus() !== InvoiceStatus::Overdue) {
                return false;
            }

            $this->change($locked, InvoiceStatus::Overdue, null);

            return true;
        });
    }

    private function lock(int $id): Invoice
    {
        return Invoice::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function assertSettleable(Invoice $locked, string $message, ?string $paidMessage = null): void
    {
        if ($locked->effectiveStatus()->isSettleable()) {
            return;
        }

        throw ValidationException::withMessages(['status' => $locked->status === InvoiceStatus::Paid ? ($paidMessage ?? $message) : $message]);
    }

    /** @param  array<string, mixed>  $extra */
    private function change(Invoice $locked, InvoiceStatus $to, ?User $actor, ?string $note = null, array $extra = []): Invoice
    {
        $from = $locked->status;
        $locked->forceFill(['status' => $to] + $extra)->save();
        (new InvoiceStatusHistory)->forceFill([
            'invoice_id' => $locked->id, 'from_status' => $from, 'to_status' => $to, 'actor_user_id' => $actor?->id, 'note' => $note,
        ])->save();

        return $locked;
    }
}
