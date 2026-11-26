<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List item. No numeric id, user id or email; `id` is the random public uuid. `status` is the EFFECTIVE status.
 * The company name is the buyer name SNAPSHOT (no company join). Needs line_count (withCount) or items loaded.
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'status' => $this->effectiveStatus()->value,
            'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents,
            'vat_rate_bp' => $this->vat_rate_bp,
            'tax_cents' => $this->tax_cents,
            'total_cents' => $this->total_cents,
            'issue_date' => $this->issue_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'paid_on' => $this->paid_on?->toDateString(),
            'days_overdue' => $this->daysOverdue(),
            'order_number' => $this->order_number,
            'po_reference' => $this->po_reference,
            'line_count' => $this->getAttribute('line_count') ?? $this->items->count(),
            'company' => ['id' => $this->company_id, 'name' => $this->buyer['name']],
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
