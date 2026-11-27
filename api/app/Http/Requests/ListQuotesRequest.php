<?php

namespace App\Http\Requests;

use App\Enums\QuoteStatus;
use App\Models\QuoteRequest;

/** Same parameters and rules as the orders list; only the status values and sort keys differ. */
class ListQuotesRequest extends ListOrdersRequest
{
    /** Additive (Phase 6): quoted quotes whose valid_until falls within the next N days (UTC), for the back-office "expiring soon" link. */
    public function rules(): array
    {
        return parent::rules() + ['expiring_within_days' => ['bail', 'nullable', 'integer', 'min:1', 'max:30']];
    }

    public function criteria(): array
    {
        $days = $this->validated('expiring_within_days');

        return parent::criteria() + ['expiring_within_days' => $days === null ? null : (int) $days];
    }

    protected function sorts(): array
    {
        return QuoteRequest::SORTS;
    }

    protected function statuses(): array
    {
        return QuoteStatus::values();
    }
}
