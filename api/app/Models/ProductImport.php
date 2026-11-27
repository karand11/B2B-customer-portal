<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A previewed CSV import. The uuid is the token only its uploader can apply. Written only by ProductImportService. */
class ProductImport extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'warnings' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }
}
