<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use Database\Factories\QuoteRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class QuoteRequest extends Model
{
    /** @use HasFactory<QuoteRequestFactory> */
    use HasFactory;

    // Nothing is mass-assigned; the services use forceFill with server values.
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'valid_until' => 'date:Y-m-d',
            'sent_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    /** Sort whitelist: request value => [column, direction]. No price or validity sort: it could leak unsent drafts. */
    public const SORTS = [
        'created_at' => ['created_at', 'asc'], '-created_at' => ['created_at', 'desc'],
        'number' => ['quote_number', 'asc'], '-number' => ['quote_number', 'desc'],
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
        return $this->hasMany(QuoteItem::class)->orderBy('position');
    }

    public function history(): HasMany
    {
        return $this->hasMany(QuoteStatusHistory::class)->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(QuoteAttachment::class)->orderBy('id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    /** The status clients see and every state check uses (see QuoteStatus::effective). */
    public function effectiveStatus(): QuoteStatus
    {
        return QuoteStatus::effective($this->status, $this->valid_until);
    }

    /** valid_until + 1 day, 00:00:00 UTC, or null. */
    public function expiresAt(): ?Carbon
    {
        return $this->valid_until?->copy()->utc()->addDay()->startOfDay();
    }

    /**
     * The company boundary. EVERY quote query in a controller starts here.
     * Customers see their own company only (nothing when they have none); staff/admin see all; any other user nothing.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isStaff()) {
            return;
        }

        $user->isCustomer() && $user->company_id !== null
            ? $query->where('quote_requests.company_id', $user->company_id)
            : $query->whereRaw('false');
    }

    /**
     * List filters from ListQuotesRequest::criteria(). The status filter works on the EFFECTIVE status
     * (same rule as QuoteStatus::effective) with today (UTC) bound as a parameter.
     *
     * @param  array<string, mixed>  $c
     */
    public function scopeFilter(Builder $query, array $c): void
    {
        $today = now()->utc()->toDateString();

        $query
            ->when($c['status'], fn ($q, $statuses) => $q->where(function ($group) use ($statuses, $today) {
                foreach ($statuses as $status) {
                    match ($status) {
                        'quoted' => $group->orWhere(fn ($w) => $w->where('quote_requests.status', 'quoted')->where('quote_requests.valid_until', '>=', $today)),
                        'expired' => $group->orWhere(fn ($w) => $w->where('quote_requests.status', 'expired')
                            ->orWhere(fn ($x) => $x->where('quote_requests.status', 'quoted')->where('quote_requests.valid_until', '<', $today))),
                        default => $group->orWhere('quote_requests.status', $status),
                    };
                }
            }))
            ->when($c['from'], fn ($q, $d) => $q->where('quote_requests.created_at', '>=', Carbon::parse($d, 'UTC')->startOfDay()))
            ->when($c['to'], fn ($q, $d) => $q->where('quote_requests.created_at', '<', Carbon::parse($d, 'UTC')->addDay()->startOfDay()))
            ->when($c['company_id'], fn ($q, $id) => $q->where('quote_requests.company_id', $id))
            // Stored status quoted and valid today or within the next N days: the "expiring soon" queue.
            ->when($c['expiring_within_days'] ?? null, fn ($q, $days) => $q->where('quote_requests.status', 'quoted')
                ->whereBetween('quote_requests.valid_until', [$today, now()->utc()->addDays($days)->toDateString()]));

        if ($c['q'] !== null) {
            // Quote number without dashes (normalised, never a LIKE pattern) OR PO reference (case-insensitive,
            // % _ \ are literal). One closure, so the OR can never escape the company scope.
            $number = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c['q']));
            $po = '%'.addcslashes($c['q'], '%_\\').'%';

            $query->where(fn ($q) => $q
                ->when($number !== '', fn ($q) => $q->whereRaw("replace(quote_requests.quote_number, '-', '') like ?", ["%{$number}%"]))
                ->orWhereRaw('quote_requests.po_reference ilike ?', [$po]));
        }

        [$column, $direction] = self::SORTS[$c['sort']];
        $query->orderBy("quote_requests.{$column}", $direction)->orderBy('quote_requests.id');
    }
}
