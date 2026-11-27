<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row of the append-only audit_log (a database trigger refuses UPDATE and DELETE). Written only by App\Services\AuditLog. */
class AuditLogEntry extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'changes' => 'array', 'metadata' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
