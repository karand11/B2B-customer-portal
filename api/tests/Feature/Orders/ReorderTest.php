<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;

beforeEach(function () {
    $this->w = orderWorld();
    $this->p1 = makeProduct('RO-1', ['price_cents' => 1000]);
    $this->p2 = makeProduct('RO-2', ['price_cents' => 2000]);
    $this->p3 = makeProduct('RO-3', ['price_cents' => 3000]);
    $this->order = placeOrderAs($this->w->a1, [[$this->p1, 2], [$this->p2, 1], [$this->p3, 4]]);
});

function reorder(string $number)
{
    return test()->postJson("/api/v1/orders/{$number}/reorder");
}

it('copies every line into the cart at current prices and reports added lines', function () {
    $this->p2->forceFill(['price_cents' => 2500])->save();

    $response = loginAs($this->w->a1)->postJson("/api/v1/orders/{$this->order->order_number}/reorder")->assertOk();

    expect(array_keys($response->json('data')))->toBe(['cart', 'added', 'skipped'])
        ->and($response->json('data.added'))->toBe([
            ['product_id' => $this->p1->id, 'part_number' => 'RO-1', 'quantity' => 2],
            ['product_id' => $this->p2->id, 'part_number' => 'RO-2', 'quantity' => 1],
            ['product_id' => $this->p3->id, 'part_number' => 'RO-3', 'quantity' => 4],
        ])
        ->and($response->json('data.skipped'))->toBe([])
        ->and($response->json('data.cart.subtotal_cents'))->toBe(2000 + 2500 + 12000)
        ->and(Order::count())->toBe(1);
});

it('skips unavailable products with their reason and covers every line exactly once', function () {
    $this->p1->forceFill(['active' => false])->save();
    $this->p2->forceFill(['price_on_request' => true, 'price_cents' => null])->save();
    $this->p3->forceFill(['stock_status' => 'out_of_stock'])->save();

    $data = loginAs($this->w->a1)->postJson("/api/v1/orders/{$this->order->order_number}/reorder")->assertOk()->json('data');

    expect($data['added'])->toBe([])
        ->and(array_column($data['skipped'], 'reason'))->toBe(['inactive', 'price_on_request', 'out_of_stock'])
        ->and($data['skipped'][0])->toBe(['product_id' => $this->p1->id, 'part_number' => 'RO-1', 'name' => 'Bearing RO-1', 'reason' => 'inactive'])
        ->and($data['cart']['line_count'])->toBe(0);
});

it('adds quantities to an existing cart line and stops at the 9999 cap', function () {
    loginAs($this->w->a1);
    fillCart($this->w->a1, [[$this->p1, 5], [$this->p3, 9998]]);

    $data = reorder($this->order->order_number)->assertOk()->json('data');

    expect(array_column($data['added'], 'quantity', 'part_number'))->toBe(['RO-1' => 2, 'RO-2' => 1])
        ->and(array_column($data['skipped'], 'reason', 'part_number'))->toBe(['RO-3' => 'quantity_limit'])
        ->and(array_column($data['cart']['items'], 'quantity', 'part_number'))->toBe(['RO-1' => 7, 'RO-3' => 9998, 'RO-2' => 1]);
});

it('skips new lines with cart_full once the cart has 100 lines', function () {
    loginAs($this->w->a1);
    foreach (Product::factory()->count(98)->create() as $product) {
        CartItem::factory()->create(['user_id' => $this->w->a1->id, 'product_id' => $product->id]);
    }
    fillCart($this->w->a1, [[$this->p1, 1]]); // 99 lines; p1 exists, p2 takes line 100, p3 is the 101st

    $data = reorder($this->order->order_number)->assertOk()->json('data');

    expect(array_column($data['added'], 'part_number'))->toBe(['RO-1', 'RO-2'])
        ->and($data['skipped'])->toHaveCount(1)->and($data['skipped'][0]['reason'])->toBe('cart_full')
        ->and($data['cart']['line_count'])->toBe(100);
});

it('works for a cancelled order, for a colleague, and never for a foreign company', function () {
    loginAs($this->w->a1)->postJson("/api/v1/orders/{$this->order->order_number}/cancel")->assertOk();

    reorder($this->order->order_number)->assertOk()->assertJsonCount(3, 'data.added');

    loginAs($this->w->a2);
    reorder($this->order->order_number)->assertOk();
    expect(CartItem::where('user_id', $this->w->a2->id)->count())->toBe(3);

    loginAs($this->w->b1);
    reorder($this->order->order_number)->assertNotFound()->assertExactJson(NOT_FOUND);
    expect(CartItem::where('user_id', $this->w->b1->id)->count())->toBe(0);
});

it('answers 404 for an unknown number and 403 for staff', function () {
    loginAs($this->w->a1);
    reorder('ORD-ZZZZZ-ZZZZZ')->assertNotFound()->assertExactJson(NOT_FOUND);

    loginAs($this->w->staff);
    reorder($this->order->order_number)->assertForbidden();
});
