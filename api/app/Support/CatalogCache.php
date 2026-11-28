<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Cached, user-independent catalog payloads. Importers, seeders and (Phase 6) CRUD must call flush(). */
class CatalogCache
{
    public const FILTERS_KEY = 'catalog:filters';

    public const CATEGORIES_KEY = 'catalog:categories';

    private const TTL = 600;

    /** Seconds the winner may take to build a value, and how long the others wait for it. */
    private const LOCK_SECONDS = 10;

    private const WAIT_SECONDS = 3;

    /** @return array<string, mixed> everything except currency */
    public static function filters(): array
    {
        return self::singleFlight(self::FILTERS_KEY, function () {
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

    /**
     * Cache::remember with a stampede guard: when the key is missing (after the TTL, and after every flush, which every product edit causes)
     * ONE request rebuilds it while the others wait up to 3 s and then read the stored value. FAIL OPEN: a store without locks, a lock that
     * cannot be had in time or an error in the lock layer never blocks a reader, it just builds the value itself (and does not store a second copy).
     *
     * @param  Closure(): array<mixed>  $build
     * @return array<mixed>
     */
    private static function singleFlight(string $key, Closure $build): array
    {
        if (is_array($hit = Cache::get($key))) {
            return $hit;
        }

        $lock = null;
        $held = false;

        try {
            $lock = Cache::lock($key.':build', self::LOCK_SECONDS);
            $lock->block(self::WAIT_SECONDS);
            $held = true;
        } catch (LockTimeoutException) {
            // The holder is slow: look once more, then build without storing.
        } catch (Throwable) {
            $lock = null; // no lock support: behave like Cache::remember
            $held = true;
        }

        try {
            if (is_array($hit = Cache::get($key))) {
                return $hit;
            }

            $value = $build();
            $held && Cache::put($key, $value, self::TTL);

            return $value;
        } finally {
            $held && $lock?->release();
        }
    }

    public static function flush(): void
    {
        Cache::forget(self::FILTERS_KEY);
        Cache::forget(self::CATEGORIES_KEY);
    }
}
