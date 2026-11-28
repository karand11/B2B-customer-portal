<?php

use App\Models\Product;
use App\Services\ProductAdmin;
use App\Services\ProductImportService;
use App\Support\CatalogCache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->w = orderWorld();
    makeProduct('CC-1', ['brand' => 'SKF', 'material' => 'Chrome steel']);
    CatalogCache::flush();
});

/** Number of SELECTs against `products` while the callback runs. */
function secProductQueries(Closure $fn): int
{
    $n = 0;
    DB::listen(function ($q) use (&$n) {
        str_contains($q->sql, 'from "products"') && $n++;
    });
    $fn();

    return $n;
}

it('builds the filters once and serves the cached value for 600 seconds', function () {
    $first = null;
    $queries = secProductQueries(function () use (&$first) {
        $first = CatalogCache::filters();
        CatalogCache::filters();
        CatalogCache::filters();
    });

    expect($first['brands'])->toBe(['SKF'])->and($queries)->toBeGreaterThan(0);
    expect(secProductQueries(fn () => CatalogCache::filters()))->toBe(0);
    expect(Cache::has(CatalogCache::FILTERS_KEY))->toBeTrue();
});

it('lets only one caller rebuild an expired value: the others wait for the lock, then read the stored copy without a query', function () {
    $lock = Cache::lock(CatalogCache::FILTERS_KEY.':build', 10);
    expect($lock->get())->toBeTrue();
    $stored = [
        'brands' => ['FROM-THE-WINNER'], 'materials' => [], 'seal_types' => [], 'stock_statuses' => [],
        'ranges' => ['inner_diameter_mm' => ['min' => 1, 'max' => 2], 'outer_diameter_mm' => ['min' => 3, 'max' => 4], 'width_mm' => ['min' => 5, 'max' => 6]],
    ];

    // The winner finishes during the reader's first wait: it stores the value and releases the lock.
    Sleep::fake();
    $slept = 0;
    Sleep::whenFakingSleep(function () use ($lock, $stored, &$slept) {
        $slept++;
        Cache::put(CatalogCache::FILTERS_KEY, $stored, 600);
        $lock->release();
    });

    $result = null;
    $queries = secProductQueries(function () use (&$result) {
        $result = CatalogCache::filters();
    });

    expect($result)->toBe($stored)->and($queries)->toBe(0)->and($slept)->toBe(1);
});

it('fails open when the lock cannot be had in time: builds the value itself and does not store a second copy', function () {
    $lock = Cache::lock(CatalogCache::FILTERS_KEY.':build', 30);
    expect($lock->get())->toBeTrue();

    $start = microtime(true);
    $value = CatalogCache::filters();
    $waited = microtime(true) - $start;

    expect($value['brands'])->toBe(['SKF'])->and($waited)->toBeGreaterThan(2.5)->toBeLessThan(6)
        ->and(Cache::has(CatalogCache::FILTERS_KEY))->toBeFalse();
    $lock->release();
});

it('fails open when the cache store cannot lock at all', function () {
    // A store that cannot lock (like memcached without the lock extension): reading and writing work, lock() throws.
    Cache::extend('sec_nolock', fn () => Cache::repository(new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null)
        {
            throw new BadMethodCallException('This cache store does not support locking.');
        }
    }));
    config(['cache.stores.sec_nolock' => ['driver' => 'sec_nolock'], 'cache.default' => 'sec_nolock']);

    expect(CatalogCache::categories())->toBeArray();
});

it('releases its lock after building, even when the build fails', function () {
    $broken = false;
    try {
        // The savepoint undoes the rename (and the aborted statement) when the build throws.
        DB::transaction(function () {
            DB::statement('ALTER TABLE categories RENAME TO categories_x');
            CatalogCache::categories();
        });
    } catch (Throwable) {
        $broken = true;
    }

    expect($broken)->toBeTrue();
    $lock = Cache::lock(CatalogCache::CATEGORIES_KEY.':build', 5);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('flushes both keys after every write path commits', function (Closure $write) {
    CatalogCache::filters();
    CatalogCache::categories();
    expect(Cache::has(CatalogCache::FILTERS_KEY))->toBeTrue()->and(Cache::has(CatalogCache::CATEGORIES_KEY))->toBeTrue();

    $write($this->w);

    expect(Cache::has(CatalogCache::FILTERS_KEY))->toBeFalse()->and(Cache::has(CatalogCache::CATEGORIES_KEY))->toBeFalse();
})->with([
    'create' => [fn ($w) => app(ProductAdmin::class)->create($w->staff, adminProductBody(['part_number' => 'CC-NEW', 'category_id' => category()->id]), true)],
    'update' => [function ($w) {
        $p = Product::where('part_number', 'CC-1')->first();
        app(ProductAdmin::class)->update($w->staff, $p, ['name' => 'Renamed'], $p->version);
    }],
    'deactivate' => [fn ($w) => app(ProductAdmin::class)->setActive($w->staff, Product::where('part_number', 'CC-1')->first(), false)],
    'activate' => [function ($w) {
        $p = makeProduct('CC-OFF', ['active' => false]);
        CatalogCache::filters();
        CatalogCache::categories();
        app(ProductAdmin::class)->setActive($w->staff, $p, true);
    }],
    'import apply' => [function ($w) {
        Storage::fake('imports');
        $csv = "part_number,name,brand,category,inner_diameter_mm,outer_diameter_mm,width_mm,material,seal_type,stock_status,price_cents\nCC-IMP,Imported,SKF,deep-groove-ball,10,30,9,Chrome steel,2rs,in_stock,100\n";
        $import = app(ProductImportService::class)->preview($w->staff, UploadedFile::fake()->createWithContent('c.csv', $csv));
        CatalogCache::filters();
        CatalogCache::categories();
        app(ProductImportService::class)->apply($w->staff, $import->id, false);
    }],
]);
