<?php

namespace App\Http\Requests;

use App\Enums\QuoteStatus;
use App\Models\QuoteRequest;

/** Same parameters and rules as the orders list; only the status values and sort keys differ. */
class ListQuotesRequest extends ListOrdersRequest
{
    protected function sorts(): array
    {
        return QuoteRequest::SORTS;
    }

    protected function statuses(): array
    {
        return QuoteStatus::values();
    }
}
