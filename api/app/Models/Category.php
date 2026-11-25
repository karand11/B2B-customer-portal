<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    // Reference data written by seeders only; nothing is mass-assigned from requests.
    protected $fillable = [];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
