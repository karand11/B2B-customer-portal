<?php

namespace App\Http\Requests;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;

/** Same parameters and rules as the orders list; the status values, sort keys, default sort and the date filter column (issue_date) differ. */
class ListInvoicesRequest extends ListOrdersRequest
{
    /** Additive (Phase 6): effectively open invoices due within the next N days (0 = due today), for the back-office "due soon" link. */
    public function rules(): array
    {
        return parent::rules() + ['due_within_days' => ['bail', 'nullable', 'integer', 'min:0', 'max:30']];
    }

    public function criteria(): array
    {
        $days = $this->validated('due_within_days');

        return ['sort' => $this->validated('sort') ?? '-issue_date', 'due_within_days' => $days === null ? null : (int) $days] + parent::criteria();
    }

    protected function sorts(): array
    {
        return Invoice::SORTS;
    }

    protected function statuses(): array
    {
        return InvoiceStatus::values();
    }
}
