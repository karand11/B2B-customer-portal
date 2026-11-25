<?php

use App\Models\CartItem;
use App\Models\Product;

beforeEach(function () {
    $this->w = orderWorld();
    $this->p = makeProduct('CT-1', ['price_cents' => 1250]);
    loginAs($this->w->a1);
});

function cartRows(): int
{
    return CartItem::count();
}

it('returns the exact cart shape, integer money and an empty cart', function () {
    $this->getJson('/api/v1/cart')->assertOk()->assertExactJson(['data' => [
        'currency' => config('catalog.currency'), 'items' => [], 'line_count' => 0, 'unit_count' => 0, 'subtotal_cents' => 0, 'can_checkout' => false,
    ]]);

    $cart = $this->postJson('/api/v1/cart/items', ['product_id' => $this->p->id, 'quantity' => 2])->assertOk()->json('data');

    expect(array_keys($cart))->toBe(['currency', 'items', 'line_count', 'unit_count', 'subtotal_cents', 'can_checkout'])
        ->and($cart['items'][0])->toBe([
            'product_id' => $this->p->id, 'part_number' => 'CT-1', 'name' => 'Bearing CT-1', 'brand' => 'SKF', 'stock_status' => 'in_stock',
            'unit_price_cents' => 1250, 'quantity' => 2, 'line_total_cents' => 2500, 'available' => true, 'unavailable_reason' => null,
        ])
        ->and($cart)->toMatchArray(['line_count' => 1, 'unit_count' => 2, 'subtotal_cents' => 2500, 'can_checkout' => true]);
});

it('adds new lines, increases existing ones, and stops at 9999', function () {
    $this->postJson('/api/v1/cart/items', ['product_id' => $this->p->id, 'quantity' => 9000])->assertOk();
    $this->postJson('/api/v1/cart/items', ['product_id' => $this->p->id, 'quantity' => 999])->assertOk()->assertJsonPath('data.items.0.quantity', 9999);

    $this->postJson('/api/v1/cart/items', ['product_id' => $this->p->id, 'quantity' => 1])->assertStatus(422)->assertJsonValidationErrors('quantity');
    expect(CartItem::value('quantity'))->toBe(9999);
});

it('limits the cart to 100 lines but still lets an existing line grow', function () {
    foreach (Product::factory()->count(100)->create() as $product) {
        CartItem::factory()->create(['user_id' => $this->w->a1->id, 'product_id' => $product->id]);
    }

    $this->postJson('/api/v1/cart/items', ['product_id' => $this->p->id, 'quantity' => 1])
        ->assertStatus(422)->assertJsonPath('errors.product_id.0', 'Your cart is full.');
    $existing = CartItem::first()->product_id;
    $this->postJson('/api/v1/cart/items', ['product_id' => $existing, 'quantity' => 1])->assertOk();
    expect(cartRows())->toBe(100);
});

it('sets an absolute quantity, removes a line (404 the second time) and clears the cart', function () {
    $other = makeProduct('CT-2');
    fillCart($this->w->a1, [[$this->p, 3], [$other, 1]]);

    $this->patchJson("/api/v1/cart/items/{$this->p->id}", ['quantity' => 7])->assertOk()->assertJsonPath('data.items.0.quantity', 7);
    $this->deleteJson("/api/v1/cart/items/{$this->p->id}")->assertOk()->assertJsonCount(1, 'data.items');
    $this->deleteJson("/api/v1/cart/items/{$this->p->id}")->assertNotFound()->assertExactJson(NOT_FOUND);
    $this->patchJson("/api/v1/cart/items/{$this->p->id}", ['quantity' => 2])->assertNotFound()->assertExactJson(NOT_FOUND);
    $this->deleteJson('/api/v1/cart')->assertOk()->assertJsonPath('data.line_count', 0);
    $this->deleteJson('/api/v1/cart')->assertOk();
    expect(cartRows())->toBe(0);
});

it('returns 404 for a malformed product id in the path and 405 for a POST on /cart', function () {
    $this->patchJson('/api/v1/cart/items/abc', ['quantity' => 1])->assertNotFound()->assertExactJson(NOT_FOUND);
    $this->deleteJson('/api/v1/cart/items/'.str_repeat('9', 19))->assertNotFound()->assertExactJson(NOT_FOUND);
    $this->postJson('/api/v1/cart')->assertStatus(405);
});

it('rejects invalid quantities with 422 on quantity and writes nothing', function (mixed $quantity) {
    $this->postJson('/api/v1/cart/items', ['product_id' => $this->p->id, 'quantity' => $quantity])
        ->assertStatus(422)->assertJsonValidationErrors('quantity');

    fillCart($this->w->a1, [[$this->p, 3]]);
    $this->patchJson("/api/v1/cart/items/{$this->p->id}", ['quantity' => $quantity])->assertStatus(422)->assertJsonValidationErrors('quantity');
    expect(CartItem::value('quantity'))->toBe(3);
})->with([0, -1, 10000, 1.5, 'abc', '2', null, [[]], [[1]], true]);

it('rejects a missing quantity and an empty body', function () {
    $this->postJson('/api/v1/cart/items', ['product_id' => $this->p->id])->assertStatus(422)->assertJsonValidationErrors('quantity');
    $this->postJson('/api/v1/cart/items')->assertStatus(422)->assertJsonValidationErrors(['product_id', 'quantity']);
    $this->patchJson("/api/v1/cart/items/{$this->p->id}")->assertStatus(422)->assertJsonValidationErrors('quantity');
    expect(cartRows())->toBe(0);
});

it('rejects an unknown, malformed or inactive product with 422 on product_id', function (mixed $productId) {
    $this->postJson('/api/v1/cart/items', ['product_id' => $productId, 'quantity' => 1])
        ->assertStatus(422)->assertJsonValidationErrors('product_id');
    expect(cartRows())->toBe(0);
})->with([999999, 0, -3, 'x', null, 1.5, '12', [[]]]);

it('gives an inactive product the same message as an unknown one', function () {
    $inactive = makeProduct('CT-OFF', ['active' => false]);

    $a = $this->postJson('/api/v1/cart/items', ['product_id' => $inactive->id, 'quantity' => 1])->assertStatus(422)->json();
    $b = $this->postJson('/api/v1/cart/items', ['product_id' => 987654, 'quantity' => 1])->assertStatus(422)->json();

    expect($a)->toBe($b)->and($a['errors']['product_id'])->toBe(['The selected product is not available.']);
});

it('blocks price on request and out of stock products but allows low stock and on order', function () {
    $por = makeProduct('CT-POR', ['price_on_request' => true, 'price_cents' => null]);
    $out = makeProduct('CT-OUT', ['stock_status' => 'out_of_stock']);

    $this->postJson('/api/v1/cart/items', ['product_id' => $por->id, 'quantity' => 1])->assertStatus(422)
        ->assertJsonPath('errors.product_id.0', 'This product is price on request and cannot be added to the cart.');
    $this->postJson('/api/v1/cart/items', ['product_id' => $out->id, 'quantity' => 1])->assertStatus(422)
        ->assertJsonPath('errors.product_id.0', 'This product is out of stock.');

    foreach (['low_stock', 'on_order'] as $status) {
        $this->postJson('/api/v1/cart/items', ['product_id' => makeProduct("CT-{$status}", ['stock_status' => $status])->id, 'quantity' => 1])->assertOk();
    }
    expect(cartRows())->toBe(2);
});

it('shows live values, flags unavailable lines in precedence order and counts only available ones', function () {
    $a = makeProduct('CT-A', ['price_cents' => 100]);
    $b = makeProduct('CT-B', ['price_cents' => 200]);
    $c = makeProduct('CT-C', ['price_cents' => 300]);
    $d = makeProduct('CT-D', ['price_cents' => 400]);
    fillCart($this->w->a1, [[$a, 1], [$b, 2], [$c, 3], [$d, 4]]);

    $this->getJson('/api/v1/cart')->assertJsonPath('data.subtotal_cents', 100 + 400 + 900 + 1600)->assertJsonPath('data.can_checkout', true);

    $a->forceFill(['price_cents' => 150])->save();
    // inactive wins over out of stock; price on request has no unit or line price.
    $b->forceFill(['active' => false, 'stock_status' => 'out_of_stock'])->save();
    $c->forceFill(['price_on_request' => true, 'price_cents' => null])->save();
    $d->forceFill(['stock_status' => 'out_of_stock'])->save();

    $cart = $this->getJson('/api/v1/cart')->assertOk()->json('data');
    expect(array_column($cart['items'], 'unavailable_reason'))->toBe([null, 'inactive', 'price_on_request', 'out_of_stock'])
        ->and(array_column($cart['items'], 'available'))->toBe([true, false, false, false])
        ->and(array_column($cart['items'], 'unit_price_cents'))->toBe([150, 200, null, 400])
        ->and(array_column($cart['items'], 'line_total_cents'))->toBe([150, 400, null, 1600])
        ->and($cart)->toMatchArray(['subtotal_cents' => 150, 'line_count' => 4, 'unit_count' => 10, 'can_checkout' => false]);
});

it('orders lines by when they were first added and ignores price fields in the body', function () {
    $second = makeProduct('CT-2ND');
    $this->postJson('/api/v1/cart/items', ['product_id' => $second->id, 'quantity' => 1, 'price' => 1, 'unit_price_cents' => 1, 'line_total_cents' => 1])->assertOk();
    $this->postJson('/api/v1/cart/items', ['product_id' => $this->p->id, 'quantity' => 1])->assertOk();
    $response = $this->postJson('/api/v1/cart/items', ['product_id' => $second->id, 'quantity' => 1])->assertOk();

    expect(array_column($response->json('data.items'), 'part_number'))->toBe(['CT-2ND', 'CT-1'])
        ->and($response->json('data.items.0.unit_price_cents'))->toBe(1250)
        ->and($response->json('data.items.0.quantity'))->toBe(2);
});
