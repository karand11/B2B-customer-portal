<?php

namespace App\Http\Resources;

use App\Models\InvoiceItem;
use App\Models\InvoiceStatusHistory;
use Illuminate\Http\Request;

/**
 * Needs items and history.actor.roles loaded. For a customer viewer staff/admin actors are anonymised and the
 * history note (the void reason) is always null.
 */
class InvoiceDetailResource extends InvoiceResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $staff = $viewer->isStaff();

        return parent::toArray($request) + [
            // Postgres jsonb reorders keys; emit them in the documented order so the body never depends on storage.
            'seller' => $this->ordered($this->seller, ['name', 'address_lines', 'tax_id', 'email', 'phone', 'bank', 'payment_terms_text', 'footer', 'vat_label']),
            'buyer' => $this->ordered($this->buyer, ['name', 'address_lines', 'tax_id']),
            'items' => $this->items->map(fn (InvoiceItem $i) => [
                'position' => $i->position,
                'part_number' => $i->part_number,
                'name' => $i->name,
                'brand' => $i->brand,
                'quantity' => $i->quantity,
                'unit_price_cents' => $i->unit_price_cents,
                'line_total_cents' => $i->line_total_cents,
            ])->all(),
            'history' => $this->history->map(function (InvoiceStatusHistory $h) use ($staff) {
                $role = $h->actor === null ? 'system' : ($h->actor->roles->first()?->name ?? 'customer');

                return [
                    'from_status' => $h->from_status?->value,
                    'to_status' => $h->to_status->value,
                    'actor' => match (true) {
                        $role === 'system' => ['name' => null, 'role' => 'system'],
                        ! $staff && $role !== 'customer' => ['name' => null, 'role' => 'staff'],
                        default => ['name' => $h->actor->name, 'role' => $staff ? $role : 'customer'],
                    },
                    'note' => $staff ? $h->note : null,
                    'created_at' => $h->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
                ];
            })->all(),
            'allowed_actions' => $this->effectiveStatus()->allowedActions($viewer),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function ordered(array $snapshot, array $keys): array
    {
        if (isset($snapshot['bank'])) {
            $snapshot['bank'] = $this->ordered($snapshot['bank'], ['account_holder', 'bank_name', 'iban', 'bic']);
        }

        return array_replace(array_flip($keys), $snapshot);
    }
}
