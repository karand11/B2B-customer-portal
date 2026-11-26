<?php

namespace App\Http\Requests;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;

/** Same parameters and rules as the orders list; the status values, sort keys, default sort and the date filter column (issue_date) differ. */
class ListInvoicesRequest extends ListOrdersRequest
{
    public function criteria(): array
    {
        return ['sort' => $this->validated('sort') ?? '-issue_date'] + parent::criteria();
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
