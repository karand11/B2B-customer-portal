<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of companies. Every change runs in one transaction with its audit row, under a row lock, with an optimistic version check.
 * It never touches invoices: an issued invoice carries its own `buyer` snapshot. A company is never deleted or deactivated.
 */
class CompanyAdmin
{
    /** @param  array{name: string, billing_address: string|null, tax_id: string|null}  $data */
    public function create(User $actor, array $data): Company
    {
        return DB::transaction(function () use ($actor, $data) {
            $company = (new Company)->forceFill([
                'name' => $data['name'], 'billing_address' => $data['billing_address'] ?? null, 'tax_id' => $data['tax_id'] ?? null,
            ]);
            $company->save();

            AuditLog::record(
                'company.created', 'company', (string) $company->id, $company->name, $actor,
                [], ['name' => $company->name, 'tax_id' => $company->tax_id], ['billing_address_changed' => $company->billing_address !== null], $company->id,
            );

            return $company;
        });
    }

    /**
     * Admin: name, billing address and tax id. Staff (updateBilling): billing address and tax id only, so `name` is absent from $data.
     *
     * @param  array{name?: string, billing_address: string|null, tax_id: string|null}  $data
     */
    public function update(User $actor, Company $company, array $data, int $version): Company
    {
        return DB::transaction(function () use ($actor, $company, $data, $version) {
            $locked = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();

            if ($locked->version !== $version) {
                throw ValidationException::withMessages(['version' => 'This record was changed by someone else. Reload it and try again.'])->status(409);
            }

            $before = ['name' => $locked->name, 'tax_id' => $locked->tax_id];
            $addressBefore = $locked->billing_address;

            $locked->forceFill($data);
            if (! $locked->isDirty()) {
                return $locked; // a no-op PUT: no write, no version bump, no audit row
            }

            $locked->save();
            $locked->refresh();

            AuditLog::record(
                'company.updated', 'company', (string) $locked->id, $locked->name, $actor,
                $before, ['name' => $locked->name, 'tax_id' => $locked->tax_id], ['billing_address_changed' => $addressBefore !== $locked->billing_address], $locked->id,
            );

            return $locked;
        });
    }

    /** The company with its live counts, its users (at most 100) and its 5 newest orders, quotes and invoices: 6 queries. */
    public function detail(int $id): Company
    {
        $company = Company::query()->withAdminCounts(Invoice::today())->findOrFail($id);

        $company->load(['users' => fn ($q) => $q->with('roles')->orderBy('id')->limit(100)]);
        $newest = fn ($model, array $columns, string $order) => $model::where('company_id', $id)->orderByDesc($order)->orderByDesc('id')->limit(5)->get($columns);

        return $company
            ->setRelation('recentOrders', $newest(Order::class, ['id', 'order_number', 'status', 'total_cents', 'currency', 'created_at'], 'created_at'))
            ->setRelation('recentQuotes', $newest(QuoteRequest::class, ['id', 'quote_number', 'status', 'valid_until', 'created_at'], 'created_at'))
            ->setRelation('recentInvoices', $newest(Invoice::class, ['id', 'public_id', 'number', 'status', 'total_cents', 'currency', 'due_date', 'created_at'], 'created_at'));
    }
}
