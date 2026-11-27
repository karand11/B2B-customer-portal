<?php

namespace App\Http\Resources;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company Needs the Company::withAdminCounts columns. The detail form (CompanyAdmin::detail) also has `users` and the three `recent*` relations loaded.
 */
class CompanyAdminResource extends JsonResource
{
    private const FORMAT = 'Y-m-d\TH:i:s\Z';

    public function toArray(Request $request): array
    {
        $detail = $this->relationLoaded('users');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'tax_id' => $this->tax_id,
            'has_billing_address' => $this->billing_address !== null && $this->billing_address !== '',
            'version' => $this->version,
            'users_count' => (int) $this->users_count,
            'open_orders_count' => (int) $this->open_orders_count,
            'open_quotes_count' => (int) $this->open_quotes_count,
            'unpaid_invoices_count' => (int) $this->unpaid_invoices_count,
            'created_at' => $this->created_at->utc()->format(self::FORMAT),
        ] + ($detail ? [
            'billing_address' => $this->billing_address,
            'users' => $this->users->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->primaryRole(), 'status' => $u->status(),
            ])->all(),
            'recent' => [
                'orders' => $this->recentOrders->map(fn ($o) => [
                    'number' => $o->order_number, 'status' => $o->status->value, 'total_cents' => $o->total_cents, 'currency' => $o->currency,
                    'created_at' => $o->created_at->utc()->format(self::FORMAT),
                ])->all(),
                'quotes' => $this->recentQuotes->map(fn ($q) => [
                    'number' => $q->quote_number, 'status' => $q->effectiveStatus()->value, 'created_at' => $q->created_at->utc()->format(self::FORMAT),
                ])->all(),
                'invoices' => $this->recentInvoices->map(fn (Invoice $i) => [
                    'id' => $i->public_id, 'number' => $i->number, 'status' => $i->effectiveStatus()->value, 'total_cents' => $i->total_cents,
                    'currency' => $i->currency, 'due_date' => $i->due_date->toDateString(),
                ])->all(),
            ],
        ] : []);
    }
}
