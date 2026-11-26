<?php

namespace App\Models;

use Database\Factories\QuoteItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** One snapshot line of a quote. Only the two price columns change after creation (staff pricing). */
class QuoteItem extends Model
{
    /** @use HasFactory<QuoteItemFactory> */
    use HasFactory;

    protected $fillable = [];
}
