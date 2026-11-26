<?php

namespace App\Http\Resources;

use App\Models\QuoteAttachment;
use App\Models\QuoteItem;
use App\Models\QuoteStatusHistory;
use Illuminate\Http\Request;

/**
 * Needs items, history.actor.roles, attachments.uploadedBy, order (number only), company and createdBy loaded.
 * For a customer viewer: staff/admin names are anonymised, staff files are omitted until sent, and internal_note
 * does not exist as a key.
 */
class QuoteDetailResource extends QuoteResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $staff = $viewer->isStaff();
        $shown = $this->revealed($request);

        $body = parent::toArray($request) + [
            'customer_note' => $this->customer_note,
            'staff_note' => $shown ? $this->staff_note : null,
        ];

        if ($staff) {
            $body['internal_note'] = $this->internal_note;
        }

        return $body + [
            'revision' => $shown ? $this->revision : null,
            'expires_at' => $shown ? $this->expiresAt()?->format('Y-m-d\TH:i:s\Z') : null,
            'items' => $this->items->map(fn (QuoteItem $i) => [
                'position' => $i->position,
                'kind' => $i->product_id === null ? 'custom' : 'catalog',
                'product_id' => $i->product_id,
                'part_number' => $i->part_number,
                'name' => $i->name,
                'brand' => $i->brand,
                'quantity' => $i->quantity,
                'unit_price_cents' => $shown ? $i->unit_price_cents : null,
                'line_total_cents' => $shown ? $i->line_total_cents : null,
            ])->all(),
            'history' => $this->history->map(function (QuoteStatusHistory $h) use ($staff) {
                $role = $h->actor === null ? 'system' : ($h->actor->roles->first()?->name ?? 'customer');

                return [
                    'from_status' => $h->from_status?->value,
                    'to_status' => $h->to_status->value,
                    'actor' => match (true) {
                        $role === 'system' => ['name' => null, 'role' => 'system'],
                        ! $staff && $role !== 'customer' => ['name' => null, 'role' => 'staff'],
                        default => ['name' => $h->actor->name, 'role' => $staff ? $role : 'customer'],
                    },
                    'note' => $h->note,
                    'created_at' => $h->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
                ];
            })->all(),
            'attachments' => $this->attachments
                ->filter(fn (QuoteAttachment $a) => $staff || $a->uploaded_by_role === 'customer' || $this->sent_at !== null)
                ->map(fn (QuoteAttachment $a) => QuoteAttachmentResource::make($a)->resolve($request))
                ->values()->all(),
            'order' => $this->order ? ['number' => $this->order->order_number] : null,
            'allowed_actions' => $this->effectiveStatus()->allowedActions($viewer),
        ];
    }
}
