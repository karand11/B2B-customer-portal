<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\QuoteRequest;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->w = quoteWorld();
});

/** Accepts a quoted quote with one catalog line (QT-100 x 10) and one custom line (x 4) through the API; returns the new order. */
function acceptedOrder(object $w): Order
{
    $quote = makeQuote($w, 'quoted');
    loginAs($w->a1)->postJson("/api/v1/quotes/{$quote->quote_number}/accept", ['revision' => 1])->assertOk();

    return Order::where('quote_request_id', $quote->id)->firstOrFail();
}

it('adds quote_number to the order list and detail: set for converted orders, null for cart orders', function () {
    $w = $this->w;
    $converted = acceptedOrder($w);
    $cart = placeOrderAs($w->a1, [[$w->priced, 1]]);
    $quoteNumber = QuoteRequest::sole()->quote_number;

    $detail = loginAs($w->a1)->getJson("/api/v1/orders/{$converted->order_number}")->assertOk();
    expect(array_keys($detail->json('data')))->toBe(DETAIL_KEYS)->and($detail->json('data.quote_number'))->toBe($quoteNumber)
        ->and($detail->json('data.items.1'))->toMatchArray(['product_id' => null, 'part_number' => null, 'brand' => null, 'name' => 'Taper roller bearing 32208, SKF or equivalent']);
    $this->getJson("/api/v1/orders/{$cart->order_number}")->assertOk()->assertJsonPath('data.quote_number', null);

    $list = $this->getJson('/api/v1/orders')->assertOk();
    expect(array_column($list->json('data'), 'quote_number', 'number'))->toEqual([$cart->order_number => null, $converted->order_number => $quoteNumber]);

    // Staff and the other company: unchanged rules.
    loginAs($w->staff)->getJson("/api/v1/orders/{$converted->order_number}")->assertOk()->assertJsonPath('data.quote_number', $quoteNumber);
    loginAs($w->b1)->getJson("/api/v1/orders/{$converted->order_number}")->assertNotFound()->assertExactJson(NOT_FOUND);
});

it('lets a converted order go through the normal order lifecycle', function () {
    $w = $this->w;
    $order = acceptedOrder($w);

    loginAs($w->staff)->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => 'confirmed'])->assertOk()->assertJsonPath('data.quote_number', fn ($n) => $n !== null);
    loginAs($w->a1)->postJson("/api/v1/orders/{$order->order_number}/cancel")->assertStatus(422);
});

it('skips custom lines on reorder with the reason not_in_catalog and covers every line once', function () {
    $w = $this->w;
    $order = acceptedOrder($w);

    $data = loginAs($w->a1)->postJson("/api/v1/orders/{$order->order_number}/reorder")->assertOk()->json('data');

    expect($data['added'])->toBe([['product_id' => $w->priced->id, 'part_number' => 'QT-100', 'quantity' => 10]])
        ->and($data['skipped'])->toBe([['product_id' => null, 'part_number' => null, 'name' => 'Taper roller bearing 32208, SKF or equivalent', 'reason' => 'not_in_catalog']])
        ->and(CartItem::where('user_id', $w->a1->id)->count())->toBe(1);
});

it('reorders an order made only of custom lines without error', function () {
    $w = $this->w;
    $quote = QuoteRequest::factory()->forCompany($w->a, $w->a1)->quoted()->withLines([['Only custom', 2, null], ['Another custom', 1, null]])->create();
    loginAs($w->a1)->postJson("/api/v1/quotes/{$quote->quote_number}/accept", ['revision' => 1])->assertOk();
    $order = Order::sole();

    $data = $this->postJson("/api/v1/orders/{$order->order_number}/reorder")->assertOk()->json('data');

    expect($data['added'])->toBe([])->and(array_column($data['skipped'], 'reason'))->toBe(['not_in_catalog', 'not_in_catalog'])->and($data['cart']['line_count'])->toBe(0);
});

it('keeps PlaceOrder working through the shared order inserter', function () {
    $w = $this->w;
    $order = placeOrderAs($w->a1, [[$w->priced, 3]], ['po_reference' => 'PO-1']);

    expect($order->quote_request_id)->toBeNull()->and($order->po_reference)->toBe('PO-1')->and($order->total_cents)->toBe(3750);
});
