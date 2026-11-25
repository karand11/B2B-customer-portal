<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const CAP = 1_000_000_000;

beforeEach(function () {
    $this->w = orderWorld();
});

it('keeps the cap constant at 10^9 cents and exposes it as a factory state', function () {
    expect(Product::MAX_PRICE_CENTS)->toBe(CAP)
        ->and(Product::factory()->atPriceCap()->make()->price_cents)->toBe(CAP);
});

it('rejects a product price above the cap in the database and accepts the cap', function () {
    $insert = fn (string $partNumber, int $price) => DB::transaction(fn () => DB::table('products')->insert([
        'category_id' => category()->id, 'part_number' => $partNumber, 'name' => 'n', 'brand' => 'SKF', 'inner_diameter_mm' => 10,
        'outer_diameter_mm' => 20, 'width_mm' => 5, 'material' => 'm', 'seal_type' => 'open', 'price_cents' => $price,
        'price_on_request' => false, 'stock_status' => 'in_stock',
    ]));

    $insert('CAP-OK', CAP);
    expect(fn () => $insert('CAP-OVER', CAP + 1))->toThrow(QueryException::class, 'products_price_max')
        ->and(fn () => $insert('CAP-HUGE', PHP_INT_MAX))->toThrow(QueryException::class)
        ->and(Product::count())->toBe(1);

    // The older CHECKs still hold.
    expect(fn () => $insert('CAP-NEG', -1))->toThrow(QueryException::class, 'products_price_nonnegative');
});

it('checks out 100 lines of 9999 units at the price cap with exact integer totals', function () {
    $lines = [];
    foreach (Product::factory()->atPriceCap()->count(100)->create() as $product) {
        $lines[] = [$product, 9999];
    }
    fillCart($this->w->a1, $lines);
    $expected = 100 * 9999 * CAP;
    expect($expected)->toBeInt()->toBe(999_900_000_000_000)->toBeLessThan(PHP_INT_MAX)->toBeLessThan(9_007_199_254_740_991);

    $cart = loginAs($this->w->a1)->getJson('/api/v1/cart')->assertOk()->json('data');
    expect($cart['subtotal_cents'])->toBeInt()->toBe($expected)
        ->and($cart['items'][0]['line_total_cents'])->toBeInt()->toBe(9999 * CAP)
        ->and($cart['can_checkout'])->toBeTrue();

    $order = $this->postJson('/api/v1/orders')->assertCreated()->json('data');

    expect($order['subtotal_cents'])->toBeInt()->toBe($expected)->and($order['total_cents'])->toBe($expected)
        ->and($order['line_count'])->toBe(100)
        ->and(Order::first()->total_cents)->toBe($expected)
        ->and(CartItem::count())->toBe(0);
    foreach ($order['items'] as $item) {
        expect($item['unit_price_cents'])->toBe(CAP)->and($item['line_total_cents'])->toBe(9999 * CAP);
    }
});
