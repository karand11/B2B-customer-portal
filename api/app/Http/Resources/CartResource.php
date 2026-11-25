<?php

namespace App\Http\Resources;

use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** Wraps the user's CartItem collection (products loaded). Values are live catalog values. */
class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Collection<int, CartItem> $lines */
        $lines = $this->resource;
        $items = $lines->map(function (CartItem $line) {
            $p = $line->product;
            $reason = $p->unavailableReason();

            return [
                'product_id' => $p->id,
                'part_number' => $p->part_number,
                'name' => $p->name,
                'brand' => $p->brand,
                'stock_status' => $p->stock_status,
                'unit_price_cents' => $p->price_cents,
                'quantity' => $line->quantity,
                'line_total_cents' => $p->price_cents === null ? null : $p->price_cents * $line->quantity,
                'available' => $reason === null,
                'unavailable_reason' => $reason,
            ];
        })->values();

        return [
            'currency' => config('catalog.currency'),
            'items' => $items->all(),
            'line_count' => $items->count(),
            'unit_count' => $lines->sum('quantity'),
            'subtotal_cents' => $items->where('available', true)->sum('line_total_cents'),
            'can_checkout' => $items->isNotEmpty() && $items->every(fn (array $i) => $i['available']),
        ];
    }
}
