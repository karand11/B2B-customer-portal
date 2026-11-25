<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    public const SEAL_TYPES = ['open', '2rs', 'zz'];

    /** Price ceiling in cents, also a DB CHECK: keeps price * 9999 * 100 lines inside a bigint and a PHP int. */
    public const MAX_PRICE_CENTS = 1_000_000_000;

    public const STOCK_STATUSES = ['in_stock', 'low_stock', 'out_of_stock', 'on_order'];

    /** Columns of the list endpoint (no description). part_number_search is generated, never written. */
    public const LIST_COLUMNS = [
        'id', 'category_id', 'part_number', 'name', 'brand', 'inner_diameter_mm', 'outer_diameter_mm',
        'width_mm', 'material', 'seal_type', 'price_cents', 'price_on_request', 'stock_status',
    ];

    // Nothing is written from requests; prices only come from seeders and products:import.
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'price_on_request' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Why this product cannot be ordered (cart/checkout/reorder rule; precedence as in the contract), or null. */
    public function unavailableReason(): ?string
    {
        return match (true) {
            ! $this->active => 'inactive',
            $this->price_on_request => 'price_on_request',
            $this->stock_status === 'out_of_stock' => 'out_of_stock',
            default => null,
        };
    }

    /** Every catalog query goes through this scope, for every role. */
    public function scopeActive(Builder $query): void
    {
        $query->where('products.active', true);
    }
}
