<?php

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Raw insert inside a savepoint so a failed statement does not poison the test transaction. */
function insertRaw(array $overrides = []): void
{
    DB::transaction(fn () => DB::table('products')->insert($overrides + [
        'category_id' => category()->id, 'part_number' => 'RAW-'.uniqid(), 'name' => 'n', 'brand' => 'SKF',
        'inner_diameter_mm' => 10, 'outer_diameter_mm' => 20, 'width_mm' => 5, 'material' => 'm',
        'seal_type' => 'open', 'price_cents' => 100, 'price_on_request' => false, 'stock_status' => 'in_stock',
    ]));
}

it('creates pg_trgm and the designed indexes', function () {
    expect(DB::selectOne("select count(*) c from pg_extension where extname = 'pg_trgm'")->c)->toBe(1);

    $indexes = collect(DB::select("select indexname, indexdef from pg_indexes where tablename in ('products','categories')"))
        ->pluck('indexdef', 'indexname');

    foreach ([
        'products_pkey', 'products_part_number_search_unique', 'products_part_number_search_trgm', 'products_category_size_idx',
        'products_brand_category_idx', 'products_size_idx', 'products_material_idx', 'products_seal_type_idx',
        'products_stock_status_idx', 'products_price_idx', 'categories_slug_unique',
    ] as $name) {
        expect($indexes)->toHaveKey($name);
    }

    expect($indexes['products_part_number_search_trgm'])->toContain('gin_trgm_ops')->toContain('WHERE active')->toContain('USING gin')
        ->and($indexes['products_category_size_idx'])->toContain('(category_id, outer_diameter_mm, inner_diameter_mm)')->toContain('WHERE active')
        ->and($indexes['products_brand_category_idx'])->toContain('(brand, category_id)')
        ->and($indexes['products_size_idx'])->toContain('(inner_diameter_mm, outer_diameter_mm, width_mm)');
});

it('fills part_number_search from the database and enforces uniqueness of the normalised value', function () {
    insertRaw(['part_number' => '6205-2RS']);

    expect(DB::table('products')->value('part_number_search'))->toBe('62052rs');
    expect(fn () => insertRaw(['part_number' => '6205 2rs']))->toThrow(QueryException::class);
});

it('cannot write part_number_search', function () {
    expect(fn () => insertRaw(['part_number' => 'GEN-1', 'part_number_search' => 'x']))->toThrow(QueryException::class);
});

it('rejects rows that violate a CHECK constraint', function (array $bad) {
    expect(fn () => insertRaw($bad))->toThrow(QueryException::class);
})->with([
    'outer equal inner' => [['inner_diameter_mm' => 20, 'outer_diameter_mm' => 20]],
    'outer below inner' => [['inner_diameter_mm' => 20, 'outer_diameter_mm' => 10]],
    'inner zero' => [['inner_diameter_mm' => 0]],
    'width zero' => [['width_mm' => 0]],
    'price with price on request' => [['price_on_request' => true, 'price_cents' => 100]],
    'no price without price on request' => [['price_on_request' => false, 'price_cents' => null]],
    'bad seal' => [['seal_type' => 'rubber']],
    'bad stock' => [['stock_status' => 'gone']],
    'negative price' => [['price_cents' => -1]],
]);

it('restricts deleting a category that has products', function () {
    $product = makeProduct('FK-1');

    expect(fn () => DB::transaction(fn () => Category::whereKey($product->category_id)->delete()))->toThrow(QueryException::class);
});

it('does not mass assign anything on Product', function () {
    expect((new Product)->getFillable())->toBe([])
        ->and(fn () => DB::transaction(fn () => Product::create(['part_number' => 'X', 'price_cents' => 1])))->toThrow(MassAssignmentException::class);
});

it('seeds the seven categories idempotently', function () {
    $this->seed(CategorySeeder::class);
    $this->seed(CategorySeeder::class);

    expect(Category::count())->toBe(7)
        ->and(Category::orderBy('sort_order')->pluck('slug')->first())->toBe('deep-groove-ball')
        ->and(Category::orderBy('sort_order')->pluck('sort_order')->all())->toBe([10, 20, 30, 40, 50, 60, 70]);
});

it('seeds demo products deterministically and idempotently', function () {
    $this->seed(ProductSeeder::class);
    $first = Product::orderBy('id')->get(['part_number', 'price_cents', 'stock_status', 'active', 'brand', 'material'])->toArray();
    $this->seed(ProductSeeder::class);
    $second = Product::orderBy('id')->get(['part_number', 'price_cents', 'stock_status', 'active', 'brand', 'material'])->toArray();

    expect(Product::where('active', true)->count())->toBe(250)
        ->and(Product::where('active', false)->count())->toBe(12)
        ->and(Product::count())->toBe(262)
        ->and(Product::distinct()->count('part_number_search'))->toBe(262)
        ->and($second)->toBe($first)
        ->and(Product::where('part_number', '6205')->exists())->toBeTrue()
        ->and(Product::where('part_number', 'HK2512')->value('inner_diameter_mm'))->toBe('25.00');
});

it('seeds a plausible distribution that satisfies every constraint', function () {
    config(['catalog.seed_count' => 400]);
    $this->seed(ProductSeeder::class);

    expect(Product::where('price_on_request', true)->whereNotNull('price_cents')->count())->toBe(0)
        ->and(Product::where('price_on_request', true)->count())->toBeGreaterThan(0)
        ->and(Product::whereRaw('outer_diameter_mm <= inner_diameter_mm')->count())->toBe(0)
        ->and(Product::distinct()->pluck('brand')->sort()->values()->all())->toBe(['FAG', 'NSK', 'NTN', 'SKF', 'Timken'])
        ->and(Product::distinct()->pluck('seal_type')->sort()->values()->all())->toBe(['2rs', 'open', 'zz'])
        ->and(Product::distinct()->pluck('stock_status')->count())->toBe(4)
        ->and(Product::whereRaw('part_number <> upper(part_number)')->count())->toBe(0)
        ->and(Product::whereNotNull('price_cents')->max('price_cents'))->toBeLessThanOrEqual(25000)
        ->and(Product::whereNotNull('price_cents')->min('price_cents'))->toBeGreaterThanOrEqual(150)
        ->and(Product::where('active', false)->count())->toBe(20);
});

it('honours CATALOG_SEED_COUNT through config and clamps it', function () {
    config(['catalog.seed_count' => 40]);
    $this->seed(ProductSeeder::class);

    expect(Product::where('active', true)->count())->toBe(40)->and(Product::where('active', false)->count())->toBe(2);
});

it('clamps the seed count and validates the currency in the config file', function () {
    // env() prefers $_SERVER/$_ENV (filled from .env) over getenv(), so set all three and restore afterwards.
    $keys = ['CATALOG_SEED_COUNT', 'CATALOG_CURRENCY'];
    $saved = [$_SERVER, $_ENV, array_map('getenv', array_combine($keys, $keys))];
    $set = function (string $key, string $value) {
        $_SERVER[$key] = $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    };

    try {
        $set('CATALOG_SEED_COUNT', '999999');
        $set('CATALOG_CURRENCY', 'eur');
        $config = require config_path('catalog.php');
        expect($config['seed_count'])->toBe(20000)->and($config['currency'])->toBe('EUR');

        $set('CATALOG_CURRENCY', 'euro!');
        expect((require config_path('catalog.php'))['currency'])->toBe('USD');
    } finally {
        [$_SERVER, $_ENV] = $saved;
        foreach ($saved[2] as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }
});

it('aborts ProductSeeder outside local/testing and skips it from DatabaseSeeder', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(fn () => app(ProductSeeder::class)->run())->toThrow(HttpException::class);

    app(DatabaseSeeder::class)->run();
    expect(Product::count())->toBe(0)->and(Category::count())->toBe(7);
})->with(['production', 'staging', 'development', 'qa']);

it('seeds products from DatabaseSeeder in testing', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Product::where('active', true)->count())->toBe(250)->and(Category::count())->toBe(7);
});

it('flushes the catalog caches when seeding', function () {
    $this->seed(CategorySeeder::class);
    cache()->put('catalog:filters', ['stale'], 600);
    cache()->put('catalog:categories', ['stale'], 600);

    $this->seed(ProductSeeder::class);

    expect(cache()->has('catalog:filters'))->toBeFalse()->and(cache()->has('catalog:categories'))->toBeFalse();
});
