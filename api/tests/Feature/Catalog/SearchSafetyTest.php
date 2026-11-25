<?php

use App\Models\Product;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach (['6205', '6205-2RS', '6305', 'NU206'] as $pn) {
        makeProduct($pn);
    }
    loginAs(makeUser());
});

it('never returns 500 for hostile q values and keeps the table intact', function (string $encoded) {
    $status = $this->getJson("/api/v1/products?q={$encoded}")->getStatusCode();

    expect($status)->toBeIn([200, 422])
        ->and(Product::count())->toBe(4)
        ->and(Schema::hasTable('products'))->toBeTrue();
})->with([
    'or 1=1' => [rawurlencode("' OR 1=1 --")],
    'drop table' => [rawurlencode("6205'; DROP TABLE products;--")],
    'double quote' => ['%22'],
    'backslash' => ['%5C'],
    'percent' => ['%25'],
    'underscore' => ['_'],
    'percents' => ['%25%25%25'],
    'single wildcard' => ['6_05'],
    'inner percent' => ['62%255'],
    'escaped percent' => ['%5C%25'],
    'emoji' => [rawurlencode("\u{1F600}")],
    'nul' => ['%00'],
    'invalid utf8' => ['%FF'],
    'nul inside' => ['62%0005'],
]);

it('never treats wildcards as match-everything', function (string $encoded) {
    $response = $this->getJson("/api/v1/products?q={$encoded}")->assertOk();

    expect($response->json('data'))->toBe([])->and($response->json('meta.total'))->toBe(0);
})->with([
    'percent' => ['%25'],
    'underscore' => ['_'],
    'many percents' => ['%25%25%25'],
    'dashes only' => ['---'],
    'single wildcard' => ['6_05'],
    'inner percent' => ['62%255'],
]);

it('strips symbols from q instead of failing', function () {
    // "62%05" normalises to 6205 (the % is removed, never a wildcard): matches the literal substring.
    expect(partNumbers($this->getJson('/api/v1/products?q=62%2505')))->toBe(['6205', '6205-2RS']);
});

it('treats leading or trailing NUL bytes as whitespace (framework trims them), without a 500', function () {
    $this->getJson('/api/v1/products?brand=SKF%00')->assertOk()->assertJsonPath('meta.total', 4);
    $this->getJson('/api/v1/products?q=%00')->assertOk();
});

it('rejects NUL and invalid UTF-8 in brand and material with 422', function (string $param, string $value) {
    $this->getJson("/api/v1/products?{$param}={$value}")->assertStatus(422)->assertJsonValidationErrors($param);
})->with([
    ['brand', '%FF'], ['brand', 'S%00KF'], ['material', '%FF'], ['material', 'a%00b'],
    ['brand', 'SKF%25'], ['brand', 'SKF_'], ['material', 'x%25'], ['brand', '%25'],
]);

it('keeps an inactive product reachable by no search term', function () {
    makeProduct('SECRET-1', ['active' => false]);

    expect($this->getJson('/api/v1/products?q=SECRET-1')->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/products?q=secret')->json('meta.total'))->toBe(0);
});
