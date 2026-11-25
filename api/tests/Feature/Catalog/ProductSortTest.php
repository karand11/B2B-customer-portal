<?php

use App\Models\Product;

beforeEach(function () {
    // part number order (normalised): a1 < b2 < c3 < d4
    makeProduct('A1', ['brand' => 'SKF', 'inner_diameter_mm' => 30, 'outer_diameter_mm' => 60, 'width_mm' => 10, 'price_cents' => 300]);
    makeProduct('B2', ['brand' => 'FAG', 'inner_diameter_mm' => 10, 'outer_diameter_mm' => 90, 'width_mm' => 30, 'price_cents' => 100]);
    makeProduct('C3', ['brand' => 'NSK', 'inner_diameter_mm' => 20, 'outer_diameter_mm' => 70, 'width_mm' => 20, 'price_cents' => 200]);
    Product::factory()->priceOnRequest()->create(['part_number' => 'D4', 'brand' => 'NTN', 'inner_diameter_mm' => 40,
        'outer_diameter_mm' => 100, 'width_mm' => 5, 'category_id' => category()->id]);
    loginAs(makeUser());
});

it('orders correctly for each of the 12 sort values', function (string $sort, array $expected) {
    expect(partNumbers($this->getJson("/api/v1/products?sort={$sort}")->assertOk()))->toBe($expected);
})->with([
    ['part_number', ['A1', 'B2', 'C3', 'D4']],
    ['-part_number', ['D4', 'C3', 'B2', 'A1']],
    ['brand', ['B2', 'C3', 'D4', 'A1']],
    ['-brand', ['A1', 'D4', 'C3', 'B2']],
    ['inner_diameter', ['B2', 'C3', 'A1', 'D4']],
    ['-inner_diameter', ['D4', 'A1', 'C3', 'B2']],
    ['outer_diameter', ['A1', 'C3', 'B2', 'D4']],
    ['-outer_diameter', ['D4', 'B2', 'C3', 'A1']],
    ['width', ['D4', 'A1', 'C3', 'B2']],
    ['-width', ['B2', 'C3', 'A1', 'D4']],
    'price puts price on request last' => ['price', ['B2', 'C3', 'A1', 'D4']],
    '-price puts price on request last' => ['-price', ['A1', 'C3', 'B2', 'D4']],
]);

it('defaults to part number order without q', function () {
    expect(partNumbers($this->getJson('/api/v1/products')))->toBe(['A1', 'B2', 'C3', 'D4']);
});

it('orders by the normalised part number, not the display value', function () {
    makeProduct('A-9');   // a9 sorts after a1, before b2
    makeProduct('A 05');  // a05 sorts before a1

    expect(partNumbers($this->getJson('/api/v1/products')))->toBe(['A 05', 'A1', 'A-9', 'B2', 'C3', 'D4']);
});

it('breaks ties by id ascending in both directions and pages never overlap', function (string $sort) {
    Product::query()->delete();
    Product::factory()->count(30)->create(['category_id' => category()->id, 'outer_diameter_mm' => 77]);
    $expected = Product::orderBy('id')->pluck('id')->all();
    $ids = [];

    foreach ([1, 2, 3, 4] as $page) {
        $ids = array_merge($ids, array_column($this->getJson("/api/v1/products?sort={$sort}&per_page=8&page={$page}")->json('data'), 'id'));
    }

    expect($ids)->toBe($expected);
})->with(['outer_diameter', '-outer_diameter']);
