<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List item. No numeric id, company_id or user id (the company object carries its id per the contract).
 * Needs company, createdBy and quote (id, quote_number) loaded, plus line_count (withCount) or items.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->order_number,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents,
            'tax_cents' => $this->tax_cents,
            'shipping_cents' => $this->shipping_cents,
            'total_cents' => $this->total_cents,
            'line_count' => $this->getAttribute('line_count') ?? $this->items->count(),
            'po_reference' => $this->po_reference,
            'quote_number' => $this->quote?->quote_number,
            'company' => ['id' => $this->company->id, 'name' => $this->company->name],
            'placed_by' => ['name' => $this->createdBy->name],
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $this->updated_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
