<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/** Cached, user-independent catalog payloads. Importers, seeders and (Phase 6) CRUD must call flush(). */
class CatalogCache
{
    public const FILTERS_KEY = 'catalog:filters';

    public const CATEGORIES_KEY = 'catalog:categories';

    private const TTL = 600;

    /** @return array<string, mixed> everything except currency */
    public static function filters(): array
    {
        return Cache::remember(self::FILTERS_KEY, self::TTL, function () {
            $distinct = fn (string $column) => Product::active()->distinct()->orderBy($column)->pluck($column)->all();
            $canonical = fn (array $order, string $column) => array_values(array_intersect($order, $distinct($column)));
            $range = Product::active()->toBase()->selectRaw(
                'min(inner_diameter_mm) as i_min, max(inner_diameter_mm) as i_max, '
                .'min(outer_diameter_mm) as o_min, max(outer_diameter_mm) as o_max, '
                .'min(width_mm) as w_min, max(width_mm) as w_max'
            )->first();

            return [
                'brands' => $distinct('brand'),
                'materials' => $distinct('material'),
                'seal_types' => $canonical(Product::SEAL_TYPES, 'seal_type'),
                'stock_statuses' => $canonical(Product::STOCK_STATUSES, 'stock_status'),
                'ranges' => [
                    'inner_diameter_mm' => ['min' => Mm::number($range->i_min), 'max' => Mm::number($range->i_max)],
                    'outer_diameter_mm' => ['min' => Mm::number($range->o_min), 'max' => Mm::number($range->o_max)],
                    'width_mm' => ['min' => Mm::number($range->w_min), 'max' => Mm::number($range->w_max)],
                ],
            ];
        });
    }

    /** @return list<array{id: int, slug: string, name: string, product_count: int}> */
    public static function categories(): array
    {
        return Cache::remember(self::CATEGORIES_KEY, self::TTL, fn () => Category::query()
            ->withCount(['products as product_count' => fn ($q) => $q->where('active', true)])
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (Category $c) => [
                'id' => $c->id, 'slug' => $c->slug, 'name' => $c->name, 'product_count' => (int) $c->product_count,
            ])->all());
    }

    public static function flush(): void
    {
        Cache::forget(self::FILTERS_KEY);
        Cache::forget(self::CATEGORIES_KEY);
    }
}
