<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    // Nothing is mass-assigned; PlaceOrder and OrderStatusChanger use forceFill with server values.
    protected $fillable = [];

    protected function casts(): array
    {
        return ['status' => OrderStatus::class];
    }

    /** Sort whitelist: request value => [column, direction]. */
    public const SORTS = [
        'created_at' => ['created_at', 'asc'], '-created_at' => ['created_at', 'desc'],
        'total' => ['total_cents', 'asc'], '-total' => ['total_cents', 'desc'],
        'number' => ['order_number', 'asc'], '-number' => ['order_number', 'desc'],
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /**
     * The company boundary. EVERY order query in a controller starts here.
     * Customers see their own company only (nothing when they have none); staff/admin see all; any other user nothing.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isStaff()) {
            return;
        }

        $user->isCustomer() && $user->company_id !== null
            ? $query->where('orders.company_id', $user->company_id)
            : $query->whereRaw('false');
    }

    /**
     * List filters from ListOrdersRequest::criteria().
     *
     * @param  array<string, mixed>  $c
     */
    public function scopeFilter(Builder $query, array $c): void
    {
        $query
            ->when($c['status'], fn ($q, $s) => $q->whereIn('orders.status', $s))
            ->when($c['from'], fn ($q, $d) => $q->where('orders.created_at', '>=', Carbon::parse($d, 'UTC')->startOfDay()))
            ->when($c['to'], fn ($q, $d) => $q->where('orders.created_at', '<', Carbon::parse($d, 'UTC')->addDay()->startOfDay()))
            ->when($c['company_id'], fn ($q, $id) => $q->where('orders.company_id', $id));

        if ($c['q'] !== null) {
            // Order number without dashes (normalised, never a LIKE pattern) OR PO reference (case-insensitive,
            // % _ \ are literal). A search that normalises to nothing still matches a literal PO substring.
            $number = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c['q']));
            $po = '%'.addcslashes($c['q'], '%_\\').'%';

            $query->where(fn ($q) => $q
                ->when($number !== '', fn ($q) => $q->whereRaw("replace(orders.order_number, '-', '') like ?", ["%{$number}%"]))
                ->orWhereRaw('orders.po_reference ilike ?', [$po]));
        }

        [$column, $direction] = self::SORTS[$c['sort']];
        $query->orderBy("orders.{$column}", $direction)->orderBy('orders.id');
    }
}
