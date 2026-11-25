<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use Illuminate\Http\Request;

/** Needs items and history.actor.roles loaded. History actors that are staff/admin are anonymised for customer viewers. */
class OrderDetailResource extends OrderResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $viewerIsStaff = $viewer->isStaff();

        return parent::toArray($request) + [
            'items' => $this->items->map(fn (OrderItem $i) => [
                'product_id' => $i->product_id,
                'part_number' => $i->part_number,
                'name' => $i->name,
                'brand' => $i->brand,
                'unit_price_cents' => $i->unit_price_cents,
                'quantity' => $i->quantity,
                'line_total_cents' => $i->line_total_cents,
            ])->all(),
            'history' => $this->history->map(function (OrderStatusHistory $h) use ($viewerIsStaff) {
                $role = $h->actor->roles->first()?->name ?? 'customer';
                $hidden = ! $viewerIsStaff && $role !== 'customer';

                return [
                    'from_status' => $h->from_status?->value,
                    'to_status' => $h->to_status->value,
                    'actor' => $hidden
                        ? ['name' => null, 'role' => 'staff']
                        : ['name' => $h->actor->name, 'role' => $viewerIsStaff ? $role : 'customer'],
                    'created_at' => $h->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
                ];
            })->all(),
            'allowed_transitions' => array_map(fn ($s) => $s->value, $this->status->allowedFor($viewer)),
        ];
    }
}
