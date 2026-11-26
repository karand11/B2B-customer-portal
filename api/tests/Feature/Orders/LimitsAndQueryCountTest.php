<?php

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\DB;

/** Query count of one request. Call warmUp() first: the user's roles are loaded (and cached on the user) by the first request only. */
function queryCount(string $method, string $uri, array $body = []): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->{$method}($uri, $body)->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function warmUp(): void
{
    test()->getJson('/api/v1/cart');
    test()->getJson('/api/v1/orders');
}

beforeEach(function () {
    $this->w = orderWorld();
    $this->p = makeProduct('LM-1');
});

it('limits POST /orders to 10 per minute per user, counting 422s, and recovers', function () {
    loginAs($this->w->a1);

    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/orders')->assertStatus(422); // empty cart
    }

    $blocked = $this->postJson('/api/v1/orders')->assertStatus(429);
    expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($blocked->json('message'))->toStartWith('Too many attempts. Please try again in ');

    // Another user is unaffected, and the cart endpoints of the blocked user still work (own bucket only).
    $this->actingAs($this->w->b1)->postJson('/api/v1/orders')->assertStatus(422);
    $this->actingAs($this->w->a1)->getJson('/api/v1/cart')->assertOk();

    $this->travel(61)->seconds();
    fillCart($this->w->a1, [[$this->p, 1]]);
    $this->postJson('/api/v1/orders')->assertCreated();
});

it('puts the role check before the orders-create limiter', function () {
    loginAs($this->w->staff);

    foreach (range(1, 12) as $i) {
        $this->postJson('/api/v1/orders')->assertForbidden();
    }
});

it('shares the 60 per minute api bucket across the Phase 3 endpoints', function () {
    loginAs($this->w->a1);

    foreach (range(1, 60) as $i) {
        $this->getJson($i % 2 ? '/api/v1/cart' : '/api/v1/orders')->assertOk();
    }

    $this->getJson('/api/v1/cart')->assertStatus(429)->assertHeader('Retry-After');
    $this->deleteJson('/api/v1/cart')->assertStatus(429);
    $this->getJson('/api/v1/orders')->assertStatus(429);
});

it('runs a constant number of queries for the list regardless of rows and per_page', function () {
    loginAs($this->w->a1);
    warmUp();
    Order::factory()->count(3)->forCompany($this->w->a, $this->w->a1)->create();
    $few = [queryCount('getJson', '/api/v1/orders'), queryCount('getJson', '/api/v1/orders?per_page=5'), queryCount('getJson', '/api/v1/orders?q=PO&status=pending,shipped&from=2026-01-01')];

    Order::factory()->count(27)->forCompany($this->w->a, $this->w->a1)->shipped()->create();
    $many = [queryCount('getJson', '/api/v1/orders'), queryCount('getJson', '/api/v1/orders?per_page=5'), queryCount('getJson', '/api/v1/orders?q=PO&status=pending,shipped&from=2026-01-01')];

    expect($many)->toBe($few)->and(max($many))->toBeLessThanOrEqual(9)
        ->and(queryCount('getJson', '/api/v1/orders?per_page=100'))->toBe(queryCount('getJson', '/api/v1/orders?per_page=5'));

    loginAs($this->w->staff);
    warmUp();
    expect(queryCount('getJson', '/api/v1/orders'))->toBeLessThanOrEqual(9);
});

it('runs a constant number of queries for the detail regardless of items and history', function () {
    $lines = fn (int $n) => array_map(fn () => [Product::factory()->create(), 1], range(1, $n));
    $small = Order::factory()->forCompany($this->w->a, $this->w->a1)->withLines($lines(1))->create();
    $large = Order::factory()->forCompany($this->w->a, $this->w->a1)->delivered()->withLines($lines(20))->create();

    foreach ([$this->w->a1, $this->w->staff] as $viewer) {
        loginAs($viewer);
        $this->getJson("/api/v1/orders/{$small->order_number}");
        $one = queryCount('getJson', "/api/v1/orders/{$small->order_number}");
        $twenty = queryCount('getJson', "/api/v1/orders/{$large->order_number}");

        expect($twenty)->toBe($one)->and($twenty)->toBeLessThanOrEqual(10);
    }
});

it('runs at most 5 queries for the cart regardless of lines', function () {
    loginAs($this->w->a1);
    warmUp();
    fillCart($this->w->a1, [[$this->p, 1]]);
    $one = queryCount('getJson', '/api/v1/cart');
    fillCart($this->w->a1, array_map(fn () => [Product::factory()->create(), 2], range(1, 15)));

    expect(queryCount('getJson', '/api/v1/cart'))->toBe($one)->and($one)->toBeLessThanOrEqual(5);
});

it('has lazy loading prevention on and no endpoint triggers a violation', function () {
    expect(Order::preventsLazyLoading())->toBeTrue();

    $order = placeOrderAs($this->w->a1, [[$this->p, 1]]);
    loginAs($this->w->a1);
    $this->getJson('/api/v1/orders')->assertOk();
    $this->getJson("/api/v1/orders/{$order->order_number}")->assertOk();
    $this->postJson("/api/v1/orders/{$order->order_number}/reorder")->assertOk();
    $this->postJson("/api/v1/orders/{$order->order_number}/cancel")->assertOk();
    Order::factory()->forCompany($this->w->a, $this->w->a1)->create();
    expect(fn () => Order::all()->first()->company)->toThrow(LazyLoadingViolationException::class);
});
