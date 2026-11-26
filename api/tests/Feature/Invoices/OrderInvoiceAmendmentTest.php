<?php

use App\Models\Invoice;
use App\Models\Order;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->w = orderWorld();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'UTC'));
});

afterEach(fn () => $this->travelBack());

it('adds invoice and can_invoice right after quote_number on the order detail only', function () {
    $order = orderWith($this->w->a, [[1000, 1]]);

    $detail = loginAs($this->w->staff)->getJson("/api/v1/orders/{$order->order_number}")->assertOk()->json('data');
    $list = $this->getJson('/api/v1/orders')->assertOk()->json('data.0');

    expect(array_keys($detail))->toBe(DETAIL_KEYS)
        ->and(array_search('invoice', array_keys($detail)))->toBe(array_search('quote_number', array_keys($detail)) + 1)
        ->and(array_search('can_invoice', array_keys($detail)))->toBe(array_search('quote_number', array_keys($detail)) + 2)
        ->and($list)->not->toHaveKey('invoice')->not->toHaveKey('can_invoice');
});

it('sets can_invoice for staff and admin only on eligible orders without an active invoice, never for customers', function (string $status, bool $eligible) {
    $order = orderWith($this->w->a, [[1000, 1]], $status);

    foreach (['staff', 'admin'] as $who) {
        loginAs($this->w->{$who})->getJson("/api/v1/orders/{$order->order_number}")->assertJsonPath('data.can_invoice', $eligible)->assertJsonPath('data.invoice', null);
    }
    loginAs($this->w->a1)->getJson("/api/v1/orders/{$order->order_number}")->assertJsonPath('data.can_invoice', false)->assertJsonPath('data.invoice', null);
})->with([['pending', false], ['confirmed', true], ['shipped', true], ['delivered', true], ['cancelled', false]]);

it('shows the non-void invoice with its effective status and turns can_invoice off', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-10-01')->dueOn('2026-11-01')->create();
    $order = Order::findOrFail($invoice->order_id);

    foreach (['staff', 'a1', 'a2'] as $who) {
        $data = loginAs($this->w->{$who})->getJson("/api/v1/orders/{$order->order_number}")->assertOk()->json('data');

        expect($data['invoice'])->toBe(['id' => $invoice->public_id, 'number' => $invoice->number, 'status' => 'overdue'])->and($data['can_invoice'])->toBeFalse();
    }
    loginAs($this->w->b1)->getJson("/api/v1/orders/{$order->order_number}")->assertNotFound();
});

it('shows a paid invoice and ignores a void one', function () {
    $paid = Invoice::factory()->forCompany($this->w->a)->paid()->create();
    $void = Invoice::factory()->forCompany($this->w->a)->void()->create();

    loginAs($this->w->staff);
    $this->getJson('/api/v1/orders/'.Order::findOrFail($paid->order_id)->order_number)->assertJsonPath('data.invoice.status', 'paid')->assertJsonPath('data.can_invoice', false);
    $this->getJson('/api/v1/orders/'.Order::findOrFail($void->order_id)->order_number)->assertJsonPath('data.invoice', null)->assertJsonPath('data.can_invoice', true);
});

it('keeps the invoice keys on the status and cancel responses too', function () {
    $order = orderWith($this->w->a, [[1000, 1]], 'pending');

    loginAs($this->w->staff)->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => 'confirmed'])->assertOk()
        ->assertJsonPath('data.can_invoice', true)->assertJsonPath('data.invoice', null);
    $cancelled = loginAs($this->w->staff)->postJson('/api/v1/orders/'.orderWith($this->w->a, [[1000, 1]], 'pending')->order_number.'/cancel')->assertOk();

    expect(array_keys($cancelled->json('data')))->toBe(DETAIL_KEYS)->and($cancelled->json('data.can_invoice'))->toBeFalse();
});
