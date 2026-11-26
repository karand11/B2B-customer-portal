<?php

namespace App\Http\Resources;

use App\Models\QuoteRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * List item. No numeric id, company_id or user id (the company object carries its id per the contract) and never internal_note.
 * Needs company and createdBy loaded, plus line_count (withCount) or items, and last_history_at (withMax) or history.
 * Prices and the validity date are hidden from customers until the quote was sent (sent_at), see revealed().
 *
 * @mixin QuoteRequest
 */
class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shown = $this->revealed($request);

        return [
            'number' => $this->quote_number,
            'status' => $this->effectiveStatus()->value,
            'currency' => $this->currency,
            'line_count' => $this->getAttribute('line_count') ?? $this->items->count(),
            'po_reference' => $this->po_reference,
            'subtotal_cents' => $shown ? $this->subtotal_cents : null,
            'tax_cents' => $this->tax_cents,
            'shipping_cents' => $this->shipping_cents,
            'total_cents' => $shown ? $this->total_cents : null,
            'valid_until' => $shown ? $this->valid_until?->toDateString() : null,
            'company' => ['id' => $this->company->id, 'name' => $this->company->name],
            'requested_by' => ['name' => $this->createdBy->name],
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $this->visibleUpdatedAt($request)->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Staff see the real updated_at. A customer must not learn when staff save draft prices or internal notes, so they get the
     * time of the last change they can see: the latest status history row or sent_at, whichever is later.
     */
    protected function visibleUpdatedAt(Request $request): Carbon
    {
        if ($request->user()->isStaff()) {
            return $this->updated_at;
        }

        $last = $this->getAttribute('last_history_at') ?? ($this->relationLoaded('history') ? $this->history->max('created_at') : null);
        $times = array_filter([$last === null ? null : Carbon::parse($last, 'UTC'), $this->sent_at, $this->created_at]);

        return max($times);
    }

    /** Staff always see everything; a customer sees prices and dates only once the quote has been sent. */
    protected function revealed(Request $request): bool
    {
        return $request->user()->isStaff() || $this->sent_at !== null;
    }
}
