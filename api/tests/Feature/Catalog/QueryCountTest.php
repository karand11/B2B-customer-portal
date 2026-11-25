<?php

use App\Models\Product;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\DB;

function queriesFor(string $uri): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->getJson($uri)->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function fill(int $n): void
{
    $tapered = category('tapered-roller');
    // Fixed anchor so the q= URI always has a hit (random Faker part numbers matched by chance).
    Product::where('part_number', 'QCANCHOR-1')->exists() || makeProduct('QCANCHOR-1');
    Product::factory()->count($n)->create(['category_id' => category()->id, 'brand' => 'SKF']);
    Product::factory()->count(5)->create(['category_id' => $tapered->id, 'brand' => 'NSK']);
}

beforeEach(fn () => loginAs(makeUser()));

it('runs a constant, small number of queries regardless of data size and per_page', function () {
    $uris = [
        '/api/v1/products',
        '/api/v1/products?q=qcanchor',
        '/api/v1/products?category=tapered-roller&brand=NSK&outer_diameter_min=10&outer_diameter_max=100',
        '/api/v1/products?per_page=5',
        '/api/v1/products?per_page=100',
        '/api/v1/products?sort=-price',
    ];

    fill(5);
    $small = array_map(queriesFor(...), $uris);
    fill(100);
    $large = array_map(queriesFor(...), $uris);

    expect($large)->toBe($small)->and(max($large))->toBeLessThanOrEqual(6);
    expect(queriesFor('/api/v1/products?per_page=5'))->toBe(queriesFor('/api/v1/products?per_page=100'));
});

it('never costs more queries for a search with no hits than for one with hits', function () {
    fill(5);

    expect(queriesFor('/api/v1/products?q=zzzznomatch'))->toBeLessThanOrEqual(queriesFor('/api/v1/products?q=qcanchor'));
});

it('runs at most 4 queries for the detail endpoint', function () {
    $product = makeProduct('QC-1');

    expect(queriesFor("/api/v1/products/{$product->id}"))->toBeLessThanOrEqual(4);
});

it('has lazy loading prevention on and the list endpoint triggers no violation', function () {
    expect(Product::preventsLazyLoading())->toBeTrue();

    fill(3);
    $this->getJson('/api/v1/products?per_page=100')->assertOk();
    expect(fn () => Product::all()->first()->category)->toThrow(LazyLoadingViolationException::class);
});

it('serves warm filters and categories without touching products or categories', function () {
    fill(3);
    $this->getJson('/api/v1/products/filters')->assertOk();
    $this->getJson('/api/v1/categories')->assertOk();

    DB::enableQueryLog();
    $this->getJson('/api/v1/products/filters')->assertOk();
    $this->getJson('/api/v1/categories')->assertOk();

    expect(DB::getQueryLog())->toBeEmpty();
});

it('runs at most 5 aggregate queries for cold filters', function () {
    fill(3);

    expect(queriesFor('/api/v1/products/filters'))->toBeLessThanOrEqual(5)
        ->and(queriesFor('/api/v1/categories'))->toBeLessThanOrEqual(1);
});
