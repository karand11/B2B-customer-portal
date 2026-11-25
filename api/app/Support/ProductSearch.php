<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/** Builds the catalog list query from validated criteria (see ListProductsRequest::criteria()). */
class ProductSearch
{
    /** Whitelist: request sort => column. The request value never reaches orderBy. */
    private const SORT_COLUMNS = [
        'part_number' => 'part_number_search', 'brand' => 'brand', 'inner_diameter' => 'inner_diameter_mm',
        'outer_diameter' => 'outer_diameter_mm', 'width' => 'width_mm', 'price' => 'price_cents',
    ];

    /** @param  array<string, mixed>  $c */
    public static function query(array $c): Builder
    {
        $query = Product::active()->select(Product::LIST_COLUMNS)->with('category:id,slug,name');
        $q = $c['q'];

        if ($q !== null) {
            // Normalised q is only a-z0-9, but escape LIKE wildcards anyway (defence in depth).
            $q === ''
                ? $query->whereRaw('1 = 0')
                : $query->where('part_number_search', 'like', '%'.addcslashes($q, '\\%_').'%');
        }

        if ($c['category_ids']) {
            $query->whereIn('category_id', $c['category_ids']);
        }

        foreach (['brand', 'material', 'seal_type', 'stock_status'] as $column) {
            if ($c[$column]) {
                $query->whereIn($column, $c[$column]);
            }
        }

        foreach ($c['ranges'] as $column => [$min, $max]) {
            $min === null || $query->where($column, '>=', $min);
            $max === null || $query->where($column, '<=', $max);
        }

        return self::order($query, $c['sort'], $q);
    }

    private static function order(Builder $query, ?string $sort, ?string $q): Builder
    {
        if ($sort === null && $q !== null && $q !== '') {
            // Exact match first, then prefix matches, then other substring matches.
            $query->orderByRaw('CASE WHEN part_number_search = ? THEN 0 WHEN part_number_search LIKE ? THEN 1 ELSE 2 END', [
                $q, addcslashes($q, '\\%_').'%',
            ]);
        }

        $sort ??= 'part_number';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = self::SORT_COLUMNS[ltrim($sort, '-')];

        $column === 'price_cents'
            ? $query->orderByRaw("price_cents {$direction} nulls last")
            : $query->orderBy($column, $direction);

        return $query->orderBy('id');
    }
}
