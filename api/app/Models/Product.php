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
            'version' => 'integer',
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

    /** Sort whitelist of the admin list: request value => column (price is handled separately: price-on-request last in both directions). */
    private const ADMIN_SORT_COLUMNS = ['part_number' => 'part_number_search', 'name' => 'name', 'brand' => 'brand', 'price' => 'price_cents', 'updated_at' => 'updated_at'];

    /**
     * Back-office list: active and inactive products, no description. Criteria come from Admin\ListProductsRequest::criteria().
     *
     * @param  array<string, mixed>  $c
     */
    public function scopeAdminList(Builder $query, array $c): void
    {
        $query->select([...self::LIST_COLUMNS, 'active', 'version', 'created_at', 'updated_at'])->with('category:id,slug,name');

        if ($c['q'] !== null) {
            $c['q'] === ''
                ? $query->whereRaw('1 = 0')
                : $query->where('part_number_search', 'like', '%'.addcslashes($c['q'], '\\%_').'%');
        }

        $c['active'] === null || $query->where('active', $c['active']);
        $c['category_ids'] && $query->whereIn('category_id', $c['category_ids']);
        $c['brand'] && $query->whereIn('brand', $c['brand']);
        $c['stock_status'] && $query->whereIn('stock_status', $c['stock_status']);

        $direction = str_starts_with($c['sort'], '-') ? 'desc' : 'asc';
        $column = self::ADMIN_SORT_COLUMNS[ltrim($c['sort'], '-')];

        $column === 'price_cents'
            ? $query->orderByRaw("price_cents {$direction} nulls last")
            : $query->orderBy($column, $direction);
        $query->orderBy('id');
    }

    /** Every catalog query goes through this scope, for every role. */
    public function scopeActive(Builder $query): void
    {
        $query->where('products.active', true);
    }
}
