<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\QuoteRequest;
use Illuminate\Http\Request;

trait FindsQuote
{
    /** The only way a controller loads a quote: through the company boundary (foreign or unknown = 404). */
    private function findQuote(Request $request, string $number): QuoteRequest
    {
        return QuoteRequest::visibleTo($request->user())->where('quote_number', strtoupper($number))->firstOrFail();
    }
}
