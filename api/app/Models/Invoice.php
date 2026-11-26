<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An issued invoice: a snapshot document. Immutable except status and paid_on (database triggers enforce it).
 * Written only by IssueInvoice, InvoiceWorkflow, InvoiceDemoSeeder and the factory (forceFill with server values).
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issue_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'paid_on' => 'date:Y-m-d',
            'seller' => 'array',
            'buyer' => 'array',
        ];
    }

    /** Sort whitelist: request value => [[column, direction], ...]. `number` orders by (year, seq), never the string. */
    public const SORTS = [
        'issue_date' => [['issue_date', 'asc']], '-issue_date' => [['issue_date', 'desc']],
        'due_date' => [['due_date', 'asc']], '-due_date' => [['due_date', 'desc']],
        'number' => [['number_year', 'asc'], ['number_seq', 'asc']], '-number' => [['number_year', 'desc'], ['number_seq', 'desc']],
        'total' => [['total_cents', 'asc']], '-total' => [['total_cents', 'desc']],
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    public function history(): HasMany
    {
        return $this->hasMany(InvoiceStatusHistory::class)->orderBy('id');
    }

    /** Today in UTC as YYYY-MM-DD: the only "today" used for overdue logic (never SQL CURRENT_DATE). */
    public static function today(): string
    {
        return now()->utc()->toDateString();
    }

    /** The status clients see and every state check uses (see InvoiceStatus::effective). */
    public function effectiveStatus(): InvoiceStatus
    {
        return InvoiceStatus::effective($this->status, $this->due_date->toDateString(), self::today());
    }

    /** Whole days past the due date while the EFFECTIVE status is overdue, else 0. */
    public function daysOverdue(): int
    {
        return $this->effectiveStatus() === InvoiceStatus::Overdue
            ? (int) Carbon::parse($this->due_date->toDateString(), 'UTC')->diffInDays(Carbon::parse(self::today(), 'UTC'))
            : 0;
    }

    /**
     * The company boundary. EVERY invoice query in a controller starts here.
     * Customers see their own company only (nothing when they have none); staff/admin see all; any other user nothing.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isStaff()) {
            return;
        }

        $user->isCustomer() && $user->company_id !== null
            ? $query->where('invoices.company_id', $user->company_id)
            : $query->whereRaw('false');
    }

    /**
     * List filters from ListInvoicesRequest::criteria(). The status filter works on the EFFECTIVE status
     * (same rule as InvoiceStatus::effective) with today (UTC) bound as a parameter. Every OR sits inside one
     * closure so it can never escape the visibleTo() scope.
     *
     * @param  array<string, mixed>  $c
     */
    public function scopeFilter(Builder $query, array $c): void
    {
        $today = self::today();

        $query
            ->when($c['status'], fn ($q, $statuses) => $q->where(function ($group) use ($statuses, $today) {
                foreach ($statuses as $status) {
                    match ($status) {
                        'open' => $group->orWhere(fn ($w) => $w->where('invoices.status', 'open')->where('invoices.due_date', '>=', $today)),
                        'overdue' => $group->orWhere(fn ($w) => $w->where('invoices.status', 'overdue')
                            ->orWhere(fn ($x) => $x->where('invoices.status', 'open')->where('invoices.due_date', '<', $today))),
                        default => $group->orWhere('invoices.status', $status),
                    };
                }
            }))
            ->when($c['from'], fn ($q, $d) => $q->where('invoices.issue_date', '>=', $d))
            ->when($c['to'], fn ($q, $d) => $q->where('invoices.issue_date', '<=', $d))
            ->when($c['company_id'], fn ($q, $id) => $q->where('invoices.company_id', $id));

        if ($c['q'] !== null) {
            // Invoice and order number without dashes (normalised, never a LIKE pattern) OR PO reference (case-insensitive,
            // % _ \ are literal). A search that normalises to nothing still matches a literal PO substring.
            $number = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c['q']));
            $po = '%'.addcslashes($c['q'], '%_\\').'%';

            $query->where(fn ($q) => $q
                ->when($number !== '', fn ($q) => $q
                    ->whereRaw("replace(invoices.number, '-', '') like ?", ["%{$number}%"])
                    ->orWhereRaw("replace(invoices.order_number, '-', '') like ?", ["%{$number}%"]))
                ->orWhereRaw('invoices.po_reference ilike ?', [$po]));
        }

        foreach (self::SORTS[$c['sort']] as [$column, $direction]) {
            $query->orderBy("invoices.{$column}", $direction);
        }
        $query->orderBy('invoices.id');
    }
}
