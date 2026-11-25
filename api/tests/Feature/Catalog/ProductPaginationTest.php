<?php

use App\Models\Product;

beforeEach(function () {
    category();
    loginAs(makeUser());
});

function seedMany(int $n): void
{
    Product::factory()->count($n)->create(['category_id' => category()->id]);
}

it('paginates with exactly the six meta keys and no links, default 24 per page', function () {
    seedMany(30);

    $page1 = $this->getJson('/api/v1/products')->assertOk();
    $page2 = $this->getJson('/api/v1/products?page=2')->assertOk();

    expect($page1->json())->toHaveKeys(['data', 'meta'])->not->toHaveKey('links')
        ->and(array_keys($page1->json('meta')))->toBe(['current_page', 'last_page', 'per_page', 'total', 'from', 'to'])
        ->and($page1->json('data'))->toHaveCount(24)
        ->and($page1->json('meta'))->toBe(['current_page' => 1, 'last_page' => 2, 'per_page' => 24, 'total' => 30, 'from' => 1, 'to' => 24])
        ->and($page2->json('data'))->toHaveCount(6)
        ->and($page2->json('meta'))->toBe(['current_page' => 2, 'last_page' => 2, 'per_page' => 24, 'total' => 30, 'from' => 25, 'to' => 30]);
});

it('walks all pages returning every id exactly once', function () {
    seedMany(30);
    $ids = [];

    foreach ([1, 2, 3, 4] as $page) {
        $ids = array_merge($ids, array_column($this->getJson("/api/v1/products?per_page=8&page={$page}")->json('data'), 'id'));
    }

    expect($ids)->toHaveCount(30)->and(array_unique($ids))->toHaveCount(30);
});

it('returns 200 with empty data and null from/to beyond the last page', function () {
    seedMany(3);

    $this->getJson('/api/v1/products?page=5')->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta', ['current_page' => 5, 'last_page' => 1, 'per_page' => 24, 'total' => 3, 'from' => null, 'to' => null]);
});

it('returns an empty first page for an empty catalog', function () {
    $this->getJson('/api/v1/products')->assertOk()
        ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'per_page' => 24, 'total' => 0, 'from' => null, 'to' => null]);
});

it('caps per_page at 100 and never returns more rows', function () {
    seedMany(105);

    expect($this->getJson('/api/v1/products?per_page=100')->assertOk()->json('data'))->toHaveCount(100);
    $this->getJson('/api/v1/products?per_page=101')->assertStatus(422)->assertJsonValidationErrors('per_page');
});

it('rejects invalid input with 422 and the parameter name as the error key', function (string $query, string $key) {
    $response = $this->getJson("/api/v1/products?{$query}")->assertStatus(422);

    expect($response->json())->toHaveKeys(['message', 'errors'])
        ->and(array_keys($response->json('errors')))->toBe([$key]);
})->with([
    ['sort=foo', 'sort'],
    ['sort=password', 'sort'],
    ['sort=id;drop', 'sort'],
    ['sort[]=part_number', 'sort'],
    ['sort=PART_NUMBER', 'sort'],
    ['seal_type=rubber', 'seal_type'],
    ['seal_type=open,rubber', 'seal_type'],
    ['stock_status=gone', 'stock_status'],
    ['category=nope', 'category'],
    ['category=deep-groove-ball,nope', 'category'],
    ['category=Deep_Groove', 'category'],
    ['outer_diameter_min=abc', 'outer_diameter_min'],
    ['outer_diameter_min=-5', 'outer_diameter_min'],
    ['outer_diameter_min=1e3', 'outer_diameter_min'],
    ['outer_diameter_min=0', 'outer_diameter_min'],
    ['outer_diameter_max=5000.01', 'outer_diameter_max'],
    ['width_min=1.234', 'width_min'],
    ['inner_diameter_max=12345', 'inner_diameter_max'],
    ['outer_diameter_min=60&outer_diameter_max=50', 'outer_diameter_min'],
    ['width_min=2&width_max=1', 'width_min'],
    ['per_page=0', 'per_page'],
    ['per_page=101', 'per_page'],
    ['per_page=abc', 'per_page'],
    ['per_page[]=5', 'per_page'],
    ['page=0', 'page'],
    ['page=-1', 'page'],
    ['page=1000001', 'page'],
    ['page=abc', 'page'],
    ['brand[]=SKF', 'brand'],
    ['material[]=x', 'material'],
    ['q[]=6205', 'q'],
    ['brand=SKF,,NSK', 'brand'],
    ['brand=,', 'brand'],
    ['brand=100%25', 'brand'],
    ['brand=a_b', 'brand'],
    ['material=Chrome%25', 'material'],
    ['brand=SK%00F', 'brand'],
    ['brand=%FF', 'brand'],
    ['material=Chr%00ome', 'material'],
    ['q=62%0005', 'q'],
    ['q=%FF', 'q'],
]);

it('rejects q longer than 64 characters and accepts exactly 64', function () {
    $this->getJson('/api/v1/products?q='.str_repeat('a', 65))->assertStatus(422)->assertJsonValidationErrors('q');
    $this->getJson('/api/v1/products?q='.str_repeat('a', 64))->assertOk();
});

it('rejects more than the allowed number of list items', function () {
    $brands = implode(',', array_map(fn ($i) => "B{$i}", range(1, 21)));

    $this->getJson("/api/v1/products?brand={$brands}")->assertStatus(422)->assertJsonValidationErrors('brand');
});

it('reports several invalid parameters at once', function () {
    $response = $this->getJson('/api/v1/products?sort=foo&per_page=0')->assertStatus(422);

    expect(array_keys($response->json('errors')))->toEqualCanonicalizing(['sort', 'per_page']);
});
