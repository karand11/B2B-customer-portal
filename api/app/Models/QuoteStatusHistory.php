<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only timeline row, written by QuoteWorkflow and CreateQuote only. A null actor is the system (expiry). */
class QuoteStatusHistory extends Model
{
    protected $table = 'quote_status_history';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['from_status' => QuoteStatus::class, 'to_status' => QuoteStatus::class];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
