<?php

namespace App\Models;

use Database\Factories\QuoteAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable file record. The stored path is random hex; original_name is sanitised display text only. */
class QuoteAttachment extends Model
{
    /** @use HasFactory<QuoteAttachmentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [];

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
