<?php

use App\Models\Company;
use App\Models\Product;
use App\Support\CatalogCache;
use Database\Seeders\CategorySeeder;

const CATALOG_URLS = ['/api/v1/products', '/api/v1/products/1', '/api/v1/products/filters', '/api/v1/categories'];

it('returns 401 JSON to guests on all four endpoints', function (string $uri) {
    $this->getJson($uri)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
})->with(CATALOG_URLS);

it('lets customer, staff and admin read all four endpoints', function (string $role) {
    $product = makeProduct('ACC-1');
    loginAs(makeUser($role));

    foreach (['/api/v1/products', "/api/v1/products/{$product->id}", '/api/v1/products/filters', '/api/v1/categories'] as $uri) {
        $this->getJson($uri)->assertOk();
    }
})->with(['customer', 'staff', 'admin']);

it('has no write routes: non-GET methods return 405 and change nothing', function (string $role, string $method) {
    $product = makeProduct('ACC-2', ['price_cents' => 500]);
    loginAs(makeUser($role));
    $count = Product::count();

    foreach (['/api/v1/products', '/api/v1/products/filters', '/api/v1/categories'] as $uri) {
        $this->json($method, $uri, ['part_number' => 'HACK', 'price_cents' => 1])->assertStatus(405);
    }
    $this->json($method, "/api/v1/products/{$product->id}", ['price_cents' => 1, 'active' => false])->assertStatus(405);

    expect(Product::count())->toBe($count)
        ->and($product->fresh()->price_cents)->toBe(500)
        ->and($product->fresh()->active)->toBeTrue();
})->with(['customer', 'staff'])->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('shares one catalog across companies: identical bodies and company_id ignored', function () {
    makeProduct('SHARED-1');
    makeProduct('SHARED-2');
    $a = makeUser('customer', Company::factory()->create());
    $b = makeUser('customer', Company::factory()->create());

    $bodies = [];
    foreach ([$a, $b] as $user) {
        $this->actingAs($user);
        $bodies[] = [
            $this->getJson('/api/v1/products')->getContent(),
            $this->getJson('/api/v1/products?company_id='.$b->company_id)->getContent(),
            $this->getJson('/api/v1/products/filters')->getContent(),
            $this->getJson('/api/v1/categories')->getContent(),
        ];
    }

    expect($bodies[0])->toBe($bodies[1])->and($bodies[0][0])->toBe($bodies[0][1]);
    expect($bodies[0][0])->not->toContain('company');
});

it('never exposes internal columns in list or detail', function () {
    $product = makeProduct('KEYS-1', ['description' => 'Secret text']);
    loginAs(makeUser());

    $list = $this->getJson('/api/v1/products')->assertOk()->json('data.0');
    $detail = $this->getJson("/api/v1/products/{$product->id}")->assertOk()->json('data');

    foreach (['active', 'description', 'company', 'company_id', 'created_at', 'updated_at', 'part_number_search', 'category_id'] as $key) {
        expect($list)->not->toHaveKey($key);
    }
    expect($detail)->not->toHaveKeys(['active', 'company_id', 'created_at', 'updated_at', 'part_number_search', 'category_id']);
});

it('serialises the ProductResource with exact keys and types', function () {
    $cat = category('tapered-roller');
    $sized = makeProduct('RES-1', [
        'category_id' => $cat->id, 'inner_diameter_mm' => 25, 'outer_diameter_mm' => 52, 'width_mm' => 16.25,
        'price_cents' => 1250, 'seal_type' => '2rs', 'stock_status' => 'low_stock', 'description' => 'Long text',
    ]);
    $onRequest = Product::factory()->priceOnRequest()->create(['part_number' => 'RES-2', 'category_id' => $cat->id]);
    loginAs(makeUser());

    $items = collect($this->getJson('/api/v1/products')->assertOk()->json('data'))->keyBy('part_number');

    expect($items['RES-1'])->toBe([
        'id' => $sized->id, 'part_number' => 'RES-1', 'name' => 'Bearing RES-1', 'brand' => 'SKF',
        'category' => ['id' => $cat->id, 'slug' => 'tapered-roller', 'name' => 'Tapered roller'],
        'inner_diameter_mm' => 25, 'outer_diameter_mm' => 52, 'width_mm' => 16.25,
        'material' => 'Chrome steel', 'seal_type' => '2rs', 'price_cents' => 1250, 'price_on_request' => false,
        'stock_status' => 'low_stock',
    ]);
    expect($items['RES-2']['price_cents'])->toBeNull()->and($items['RES-2']['price_on_request'])->toBeTrue();

    $detail = $this->getJson("/api/v1/products/{$sized->id}")->assertOk()->json('data');
    expect($detail)->toBe($items['RES-1'] + ['description' => 'Long text'])
        ->and($this->getJson("/api/v1/products/{$onRequest->id}")->json('data.description'))->toBeNull();
});

it('hides inactive products everywhere, for every role', function (string $role) {
    makeProduct('ACT-1', ['brand' => 'SKF', 'outer_diameter_mm' => 60]);
    $hidden = makeProduct('HIDDEN-1', ['brand' => 'Timken', 'outer_diameter_mm' => 500, 'active' => false, 'seal_type' => 'open']);
    $empty = category('needle-roller');
    makeProduct('HIDDEN-2', ['category_id' => $empty->id, 'active' => false]);
    loginAs(makeUser($role));

    expect(partNumbers($this->getJson('/api/v1/products')))->toBe(['ACT-1'])
        ->and(partNumbers($this->getJson('/api/v1/products?q=HIDDEN-1')))->toBe([])
        ->and($this->getJson('/api/v1/products?q=HIDDEN-1')->json('meta.total'))->toBe(0);
    $this->getJson("/api/v1/products/{$hidden->id}")->assertNotFound()->assertExactJson(['message' => 'Not found.']);

    $filters = $this->getJson('/api/v1/products/filters')->assertOk()->json('data');
    expect($filters['brands'])->toBe(['SKF'])
        ->and($filters['ranges']['outer_diameter_mm']['max'])->toBe(60);

    $categories = collect($this->getJson('/api/v1/categories')->json('data'))->keyBy('slug');
    expect($categories['deep-groove-ball']['product_count'])->toBe(1)
        ->and($categories['needle-roller']['product_count'])->toBe(0);
})->with(['customer', 'staff', 'admin']);

it('returns 404, never 500, for unknown or malformed ids and does not capture /filters', function (string $id) {
    config(['app.debug' => false]);
    loginAs(makeUser());

    $this->getJson("/api/v1/products/{$id}")->assertNotFound()->assertExactJson(['message' => 'Not found.']);
})->with(['999999', 'abc', '-1', '99999999999999999999999', '1.5', '0x1', '1%20', '%00']);

it('returns the same generic 404 body for an unknown api route, with and without debug', function (bool $debug) {
    config(['app.debug' => $debug]);
    loginAs(makeUser());

    $this->getJson('/api/v1/does-not-exist')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    $this->getJson('/api/v1/products/999999')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
})->with([false, true]);

it('routes /products/filters to the filters endpoint', function () {
    loginAs(makeUser());

    $this->getJson('/api/v1/products/filters')->assertOk()->assertJsonStructure(['data' => ['currency', 'brands', 'materials', 'seal_types', 'stock_statuses', 'ranges']]);
});

it('returns filter options with canonical order and exact ranges', function () {
    makeProduct('F-1', ['brand' => 'SKF', 'material' => 'Chrome steel', 'seal_type' => 'zz', 'stock_status' => 'on_order',
        'inner_diameter_mm' => 3, 'outer_diameter_mm' => 10, 'width_mm' => 3]);
    makeProduct('F-2', ['brand' => 'FAG', 'material' => 'Ceramic', 'seal_type' => 'open', 'stock_status' => 'in_stock',
        'inner_diameter_mm' => 120, 'outer_diameter_mm' => 215.5, 'width_mm' => 60]);
    loginAs(makeUser());

    $this->getJson('/api/v1/products/filters')->assertOk()->assertExactJson(['data' => [
        'currency' => 'USD',
        'brands' => ['FAG', 'SKF'],
        'materials' => ['Ceramic', 'Chrome steel'],
        'seal_types' => ['open', 'zz'],
        'stock_statuses' => ['in_stock', 'on_order'],
        'ranges' => [
            'inner_diameter_mm' => ['min' => 3, 'max' => 120],
            'outer_diameter_mm' => ['min' => 10, 'max' => 215.5],
            'width_mm' => ['min' => 3, 'max' => 60],
        ],
    ]]);
});

it('returns empty filter lists and null ranges for an empty catalog', function () {
    loginAs(makeUser());

    $this->getJson('/api/v1/products/filters')->assertOk()->assertJsonPath('data.brands', [])
        ->assertJsonPath('data.ranges.width_mm', ['min' => null, 'max' => null]);
});

it('lists categories in sort order with active product counts', function () {
    $this->seed(CategorySeeder::class);
    makeProduct('C-1', ['category_id' => category('tapered-roller')->id]);
    makeProduct('C-2', ['category_id' => category('tapered-roller')->id, 'active' => false]);
    loginAs(makeUser());

    $data = $this->getJson('/api/v1/categories')->assertOk()->json('data');

    expect($data)->toHaveCount(7)
        ->and(array_column($data, 'slug')[0])->toBe('deep-groove-ball')
        ->and(array_keys($data[0]))->toBe(['id', 'slug', 'name', 'product_count'])
        ->and(collect($data)->firstWhere('slug', 'tapered-roller')['product_count'])->toBe(1);
});

it('returns the configured currency at request time, not from the cache', function () {
    loginAs(makeUser());

    $this->getJson('/api/v1/products/filters')->assertJsonPath('data.currency', 'USD');
    config(['catalog.currency' => 'EUR']);
    $this->getJson('/api/v1/products/filters')->assertJsonPath('data.currency', 'EUR');
});

it('caches filters and categories until flushed', function () {
    makeProduct('CACHE-1', ['brand' => 'SKF']);
    loginAs(makeUser());
    $this->getJson('/api/v1/products/filters');
    $this->getJson('/api/v1/categories');

    makeProduct('CACHE-2', ['brand' => 'NSK']);
    $this->getJson('/api/v1/products/filters')->assertJsonPath('data.brands', ['SKF']);
    $this->getJson('/api/v1/categories')->assertJsonPath('data.0.product_count', 1);

    CatalogCache::flush();
    $this->getJson('/api/v1/products/filters')->assertJsonPath('data.brands', ['NSK', 'SKF']);
    $this->getJson('/api/v1/categories')->assertJsonPath('data.0.product_count', 2);
});

it('rate limits at 60 requests per minute per user, shared across the four endpoints', function () {
    $user = makeUser();
    $other = makeUser();
    loginAs($user);

    foreach (range(1, 15) as $i) {
        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/products/filters')->assertOk();
        $this->getJson('/api/v1/categories')->assertOk();
        $this->getJson('/api/v1/products/999')->assertNotFound();
    }

    $limited = $this->getJson('/api/v1/products')->assertStatus(429);
    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($limited->json('message'))->toStartWith('Too many attempts. Please try again in ');

    $this->actingAs($other)->getJson('/api/v1/products')->assertOk();

    $this->travel(61)->seconds();
    $this->actingAs($user)->getJson('/api/v1/products')->assertOk();
});
