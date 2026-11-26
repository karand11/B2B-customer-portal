<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only timeline row, written by IssueInvoice, InvoiceWorkflow and the seeder/factory only. A null actor is the system (overdue). */
class InvoiceStatusHistory extends Model
{
    protected $table = 'invoice_status_history';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['from_status' => InvoiceStatus::class, 'to_status' => InvoiceStatus::class];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
