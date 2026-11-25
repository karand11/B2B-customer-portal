<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;

beforeEach(function () {
    $this->w = orderWorld();
    $this->pa = makeProduct('A-ONLY-1', ['price_cents' => 1111]);
    $this->pb = makeProduct('B-SECRET-9', ['price_cents' => 7777]);
    $this->orderA = placeOrderAs($this->w->a1, [[$this->pa, 2]]);
    $this->orderB = placeOrderAs($this->w->b1, [[$this->pb, 3]]);
    // Fixed numbers: the fragment searches below must not depend on random data.
    $this->orderA->forceFill(['order_number' => 'ORD-22345-67892'])->save();
    $this->orderB->forceFill(['order_number' => 'ORD-K7Q2M-X9PA3'])->save();
});

it('hides another company order: detail 404 with the exact body, same as an unknown number', function (bool $debug) {
    config(['app.debug' => $debug]);
    loginAs($this->w->a1);

    $foreign = $this->getJson("/api/v1/orders/{$this->orderB->order_number}")->assertNotFound()->assertExactJson(NOT_FOUND);
    $unknown = $this->getJson('/api/v1/orders/ORD-ZZZZZ-ZZZZZ')->assertNotFound()->assertExactJson(NOT_FOUND);

    expect($foreign->getContent())->toBe($unknown->getContent());
})->with([true, false]);

it('never lists another company order and counts only its own', function () {
    $response = loginAs($this->w->a1)->getJson('/api/v1/orders')->assertOk();

    expect(array_column($response->json('data'), 'number'))->toBe([$this->orderA->order_number])
        ->and($response->json('meta.total'))->toBe(1)
        ->and($response->getContent())->not->toContain($this->orderB->order_number)
        ->not->toContain('B-SECRET-9')->not->toContain('7777');

    // The mirror image.
    $mine = loginAs($this->w->b1)->getJson('/api/v1/orders')->assertOk();
    expect(array_column($mine->json('data'), 'number'))->toBe([$this->orderB->order_number]);
    $this->getJson("/api/v1/orders/{$this->orderA->order_number}")->assertNotFound()->assertExactJson(NOT_FOUND);
});

it('cannot cancel another company order and leaves it untouched', function () {
    loginAs($this->w->a1)->postJson("/api/v1/orders/{$this->orderB->order_number}/cancel")->assertNotFound()->assertExactJson(NOT_FOUND);

    expect($this->orderB->fresh()->status->value)->toBe('pending')
        ->and(OrderStatusHistory::where('order_id', $this->orderB->id)->count())->toBe(1);
});

it('lets a colleague of the same company read, list and cancel a pending order', function () {
    loginAs($this->w->a2);

    $this->getJson("/api/v1/orders/{$this->orderA->order_number}")->assertOk()->assertJsonPath('data.placed_by.name', $this->w->a1->name);
    expect(array_column($this->getJson('/api/v1/orders')->json('data'), 'number'))->toBe([$this->orderA->order_number]);
    $this->postJson("/api/v1/orders/{$this->orderA->order_number}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('answers every endpoint for a foreign order with 404 or 403 and never leaks it', function () {
    loginAs($this->w->a1);
    fillCart($this->w->a1, [[$this->pa, 4]]);
    $cartBefore = $this->getJson('/api/v1/cart')->assertOk()->json();

    $n = $this->orderB->order_number;
    $this->postJson("/api/v1/orders/{$n}/reorder")->assertNotFound()->assertExactJson(NOT_FOUND);
    $this->patchJson("/api/v1/orders/{$n}/status", ['status' => 'confirmed'])->assertForbidden();
    $this->getJson("/api/v1/orders?q={$n}")->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/orders?q='.substr($n, 4, 5))->assertOk()->assertJsonPath('meta.total', 0);

    expect($this->getJson('/api/v1/cart')->json())->toBe($cartBefore);
    foreach (['/api/v1/orders', '/api/v1/cart', "/api/v1/orders/{$this->orderA->order_number}"] as $uri) {
        expect($this->getJson($uri)->getContent())->not->toContain('B-SECRET-9')->not->toContain('7777')->not->toContain('Globex Test');
    }
    expect($this->orderB->fresh()->status->value)->toBe('pending');
});

it('ignores a company_id query parameter from a customer', function () {
    $response = loginAs($this->w->a1)->getJson("/api/v1/orders?company_id={$this->w->b->id}")->assertOk();

    expect(array_column($response->json('data'), 'number'))->toBe([$this->orderA->order_number]);
    $this->getJson('/api/v1/orders?company_id=abc')->assertOk();
});

it('shows staff and admin every company, filterable by company', function (string $role) {
    loginAs($this->w->{$role});

    $all = $this->getJson('/api/v1/orders')->assertOk();
    expect($all->json('meta.total'))->toBe(2)
        ->and(array_column(array_column($all->json('data'), 'company'), 'name'))->toEqualCanonicalizing(['Northwind Test Ltd', 'Globex Test Ltd']);

    expect(array_column($this->getJson("/api/v1/orders?company_id={$this->w->a->id}")->json('data'), 'number'))->toBe([$this->orderA->order_number]);
    $this->getJson('/api/v1/orders?company_id=9999999')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/orders?company_id=abc')->assertStatus(422)->assertJsonValidationErrors('company_id');
    $this->getJson('/api/v1/orders?company_id=0')->assertStatus(422)->assertJsonValidationErrors('company_id');
    $this->getJson("/api/v1/orders/{$this->orderB->order_number}")->assertOk()->assertJsonPath('data.company.name', 'Globex Test Ltd');
})->with(['staff', 'admin']);

it('keeps staff and admin out of cart, checkout and reorder with a 403 that does not depend on the order', function (string $role) {
    loginAs($this->w->{$role});

    $this->getJson('/api/v1/cart')->assertForbidden();
    $this->postJson('/api/v1/cart/items', ['product_id' => $this->pa->id, 'quantity' => 1])->assertForbidden();
    $this->patchJson("/api/v1/cart/items/{$this->pa->id}", ['quantity' => 1])->assertForbidden();
    $this->deleteJson("/api/v1/cart/items/{$this->pa->id}")->assertForbidden();
    $this->deleteJson('/api/v1/cart')->assertForbidden();
    $this->postJson('/api/v1/orders')->assertForbidden();
    $this->postJson("/api/v1/orders/{$this->orderA->order_number}/reorder")->assertForbidden();
    $this->postJson('/api/v1/orders/ORD-ZZZZZ-ZZZZZ/reorder')->assertForbidden();
})->with(['staff', 'admin']);

it('gives guests 401 on every endpoint, with or without an Accept header', function (bool $accept) {
    app('auth')->forgetGuards(); // beforeEach placed orders as logged-in users
    $accept || $this->withoutHeader('Accept');
    $n = $this->orderA->order_number;

    foreach ([
        ['get', '/api/v1/cart'], ['post', '/api/v1/cart/items'], ['patch', '/api/v1/cart/items/1'], ['delete', '/api/v1/cart/items/1'],
        ['delete', '/api/v1/cart'], ['get', '/api/v1/orders'], ['get', "/api/v1/orders/{$n}"], ['post', '/api/v1/orders'],
        ['post', "/api/v1/orders/{$n}/cancel"], ['post', "/api/v1/orders/{$n}/reorder"], ['patch', "/api/v1/orders/{$n}/status"],
    ] as [$method, $uri]) {
        $this->{$method}($uri)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }
})->with([true, false]);

it('gives customers 403 on the status endpoint for own, foreign and unknown numbers', function () {
    loginAs($this->w->a1);

    foreach ([$this->orderA->order_number, $this->orderB->order_number, 'ORD-ZZZZZ-ZZZZZ'] as $n) {
        $this->patchJson("/api/v1/orders/{$n}/status", ['status' => 'confirmed'])->assertForbidden();
    }
    $this->patchJson("/api/v1/orders/{$this->orderA->order_number}/status", ['status' => 'nonsense'])->assertForbidden();
    expect($this->orderA->fresh()->status->value)->toBe('pending');
});

it('treats a number that does not match the route pattern as 404', function () {
    loginAs($this->w->a1);

    $this->getJson('/api/v1/orders/'.str_repeat('A', 33))->assertNotFound()->assertExactJson(NOT_FOUND);
    $this->getJson('/api/v1/orders/ORD_K7Q2M')->assertNotFound()->assertExactJson(NOT_FOUND);
    $this->getJson('/api/v1/orders/'.strtolower($this->orderA->order_number))->assertOk();
});

it('gives every user an own cart and never lets one touch the line of another', function () {
    $mine = makeProduct('ONLY-A1-1');
    $theirs = makeProduct('ONLY-A2-1');
    fillCart($this->w->a1, [[$mine, 2]]);
    fillCart($this->w->a2, [[$theirs, 5]]);

    loginAs($this->w->a1);
    expect(array_column($this->getJson('/api/v1/cart')->json('data.items'), 'part_number'))->toBe(['ONLY-A1-1']);
    $this->patchJson("/api/v1/cart/items/{$theirs->id}", ['quantity' => 1])->assertNotFound()->assertExactJson(NOT_FOUND);
    $this->deleteJson("/api/v1/cart/items/{$theirs->id}")->assertNotFound()->assertExactJson(NOT_FOUND);
    expect(CartItem::where('user_id', $this->w->a2->id)->value('quantity'))->toBe(5);

    // Checkout consumes only the caller's lines, and user_id / company_id in the request are ignored.
    $this->getJson("/api/v1/cart?user_id={$this->w->a2->id}&company_id={$this->w->b->id}")->assertJsonCount(1, 'data.items');
    $this->postJson('/api/v1/cart/items', ['product_id' => $this->pa->id, 'quantity' => 1, 'user_id' => $this->w->a2->id, 'company_id' => $this->w->b->id])->assertOk();
    $this->postJson('/api/v1/orders', ['user_id' => $this->w->a2->id, 'company_id' => $this->w->b->id])->assertCreated();

    expect(CartItem::where('user_id', $this->w->a2->id)->count())->toBe(1)
        ->and(CartItem::where('user_id', $this->w->a1->id)->count())->toBe(0);
    $this->actingAs($this->w->a2)->getJson('/api/v1/cart')->assertJsonPath('data.items.0.part_number', 'ONLY-A2-1');
});

it('shows only B lines to B and never mixes products between orders', function () {
    $detail = loginAs($this->w->b1)->getJson("/api/v1/orders/{$this->orderB->order_number}")->assertOk();

    expect($detail->getContent())->toContain('B-SECRET-9')->not->toContain('A-ONLY-1')
        ->and(Product::count())->toBe(2)
        ->and(Order::count())->toBe(2);
});
