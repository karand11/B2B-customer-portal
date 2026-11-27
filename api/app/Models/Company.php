<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    // Written only by CompanyAdmin and the seeders (forceFill with server-built arrays).
    protected $fillable = [];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Back-office columns: the live counts as correlated subqueries (effective statuses, today bound as a parameter),
     * so a list of any size is one query. Open quotes: submitted, in_review, or quoted and not yet past valid_until.
     */
    public function scopeWithAdminCounts(Builder $query, string $today): void
    {
        $query->select('companies.*')
            ->selectRaw('(select count(*) from users where users.company_id = companies.id) as users_count')
            ->selectRaw("(select count(*) from orders where orders.company_id = companies.id and orders.status in ('pending', 'confirmed', 'shipped')) as open_orders_count")
            ->selectRaw("(select count(*) from quote_requests where quote_requests.company_id = companies.id and (quote_requests.status in ('submitted', 'in_review') or (quote_requests.status = 'quoted' and quote_requests.valid_until >= ?))) as open_quotes_count", [$today])
            ->selectRaw("(select count(*) from invoices where invoices.company_id = companies.id and invoices.status in ('open', 'overdue')) as unpaid_invoices_count");
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function quoteRequests(): HasMany
    {
        return $this->hasMany(QuoteRequest::class);
    }
}
