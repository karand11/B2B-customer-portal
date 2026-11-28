<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;

const SEC_PRICES_CHANGED = 'The prices in your cart changed. Review the cart and place the order again.';

beforeEach(function () {
    $this->w = orderWorld();
    $this->p1 = makeProduct('CK-1', ['price_cents' => 1000]);
    $this->p2 = makeProduct('CK-2', ['price_cents' => 250]);
    fillCart($this->w->a1, [[$this->p1, 2], [$this->p2, 4]]); // 2 x 1000 + 4 x 250 = 3000
    $this->actingAs($this->w->a1);
});

it('places the order when the expected subtotal equals the cart subtotal', function () {
    $response = $this->postJson('/api/v1/orders', ['po_reference' => 'PO-1', 'expected_subtotal_cents' => 3000])->assertCreated();

    expect($response->json('data.subtotal_cents'))->toBe(3000)->and(Order::count())->toBe(1)->and(CartItem::count())->toBe(0);
});

it('behaves exactly as before when the field is absent or null', function (array $body) {
    $this->postJson('/api/v1/orders', $body)->assertCreated()->assertJsonPath('data.subtotal_cents', 3000);
})->with([[[]], [['expected_subtotal_cents' => null]], [['po_reference' => 'PO-2']]]);

it('answers 409 with the fresh cart when a price changed, and creates nothing', function () {
    $this->p1->forceFill(['price_cents' => 1100])->save();
    $cartBefore = CartItem::orderBy('id')->get(['product_id', 'quantity'])->toArray();

    $response = $this->postJson('/api/v1/orders', ['po_reference' => 'PO-3', 'expected_subtotal_cents' => 3000])->assertStatus(409);

    expect($response->json())->toHaveKeys(['message', 'errors', 'cart'])
        ->and($response->json('message'))->toBe(SEC_PRICES_CHANGED)
        ->and($response->json('errors'))->toBe(['expected_subtotal_cents' => [SEC_PRICES_CHANGED]])
        ->and($response->json('cart.subtotal_cents'))->toBe(3200)
        ->and($response->json('cart'))->toBe($this->getJson('/api/v1/cart')->json('data'))
        ->and(Order::count())->toBe(0)
        ->and(OrderStatusHistory::count())->toBe(0)
        ->and(CartItem::orderBy('id')->get(['product_id', 'quantity'])->toArray())->toBe($cartBefore);
});

it('lets the customer place the order after the 409 with the new subtotal', function () {
    $this->p2->forceFill(['price_cents' => 300])->save();

    $this->postJson('/api/v1/orders', ['expected_subtotal_cents' => 3000])->assertStatus(409);
    $this->postJson('/api/v1/orders', ['expected_subtotal_cents' => 3200])->assertCreated()->assertJsonPath('data.subtotal_cents', 3200);
});

it('refuses a wrong-typed or out-of-range expected subtotal with 422 on that key, before the cart is read', function (mixed $value) {
    CartItem::query()->delete(); // an empty cart would be 422 `cart`: the field error must come first

    $response = $this->postJson('/api/v1/orders', ['expected_subtotal_cents' => $value])->assertStatus(422);

    expect(array_keys($response->json('errors')))->toBe(['expected_subtotal_cents']);
})->with([['3000'], [30.5], [true], [false], [-1], [100000000000000], [[3000]], [['a' => 1]]]);

it('accepts the whole documented range as a type: 0 and 99,999,999,999,999', function (int $value) {
    $this->postJson('/api/v1/orders', ['expected_subtotal_cents' => $value])->assertStatus(409);
})->with([[0], [99999999999999]]);

it('checks po_reference before the subtotal and the cart before the subtotal', function () {
    $this->postJson('/api/v1/orders', ['po_reference' => str_repeat('x', 65), 'expected_subtotal_cents' => 1])->assertStatus(422)->assertJsonValidationErrors('po_reference');

    $this->p1->forceFill(['stock_status' => 'out_of_stock'])->save();
    $response = $this->postJson('/api/v1/orders', ['expected_subtotal_cents' => 1])->assertStatus(422);
    expect(array_keys($response->json('errors')))->toBe(['cart']);

    CartItem::query()->delete();
    $response = $this->postJson('/api/v1/orders', ['expected_subtotal_cents' => 1])->assertStatus(422);
    expect(array_keys($response->json('errors')))->toBe(['cart']);
});

it('never creates an order at a price other than the one it validated, even when a price changes while the cart is being read', function () {
    $armed = true;
    CartItem::retrieved(function () use (&$armed) {
        // Between the cart lines and their products: the worst moment for a price edit (inside the checkout transaction).
        $armed && Product::whereKey($this->p1->id)->update(['price_cents' => 2000]);
        $armed = false;
    });

    // The customer saw 3000. The price is 2000 by the time the products are read: 409, nothing created (the edit made inside the transaction is rolled back with it).
    $this->postJson('/api/v1/orders', ['expected_subtotal_cents' => 3000])->assertStatus(409);
    expect(Order::count())->toBe(0);

    // The customer expected 5000 and the price moves to 2000 mid-request: the order is created at exactly the validated price.
    $armed = true;
    $response = $this->postJson('/api/v1/orders', ['expected_subtotal_cents' => 5000])->assertCreated();
    expect($response->json('data.subtotal_cents'))->toBe(5000)->and($response->json('data.items.0.unit_price_cents'))->toBe(2000);
});

it('counts every attempt toward the orders-create limit, a 409 included', function () {
    $this->p1->forceFill(['price_cents' => 1100])->save();

    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/orders', ['expected_subtotal_cents' => 3000])->assertStatus(409);
    }

    $this->postJson('/api/v1/orders', ['expected_subtotal_cents' => 3200])->assertStatus(429);
});

it('keeps 401, 403 and the role order for the new field', function () {
    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/orders', ['expected_subtotal_cents' => 'x'])->assertUnauthorized();

    $this->actingAs($this->w->staff)->postJson('/api/v1/orders', ['expected_subtotal_cents' => 'x'])->assertForbidden();
});
