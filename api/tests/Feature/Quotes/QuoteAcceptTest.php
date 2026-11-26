<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\QuoteItem;
use App\Models\QuoteRequest;
use App\Models\QuoteStatusHistory;
use App\Services\AcceptQuote;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-11-25 12:00:00', 'UTC'));
    $this->w = quoteWorld();
    $this->quote = makeQuote($this->w, 'quoted', ['po_reference' => 'NW-PO-9']);
    $this->n = $this->quote->quote_number;
});

afterEach(fn () => $this->travelBack());

function accept(string $number, array $body = ['revision' => 1])
{
    return test()->postJson("/api/v1/quotes/{$number}/accept", $body);
}

it('converts an accepted quote into exactly one pending order with the agreed prices', function () {
    $w = $this->w;
    // Catalog changes after the quote was sent must not matter.
    $w->priced->forceFill(['price_cents' => 99999, 'name' => 'Renamed', 'active' => false])->save();

    $response = loginAs($w->a1)->postJson("/api/v1/quotes/{$this->n}/accept", ['revision' => 1, 'status' => 'declined', 'total_cents' => 1, 'company_id' => $w->b->id])->assertOk();

    expect($response->json('data.status'))->toBe('accepted')->and($response->json('data.allowed_actions'))->toBe([])
        ->and($response->json('data.order.number'))->toMatch('/^ORD-/')
        ->and(array_keys($response->json('data')))->toBe(QUOTE_DETAIL_KEYS);

    $order = Order::sole();
    expect($order->order_number)->toBe($response->json('data.order.number'))->and($order->quote_request_id)->toBe($this->quote->id)
        ->and($order->status->value)->toBe('pending')->and($order->company_id)->toBe($w->a->id)->and($order->created_by_user_id)->toBe($w->a1->id)
        ->and($order->subtotal_cents)->toBe(18000)->and($order->total_cents)->toBe(18000)->and($order->tax_cents)->toBe(0)->and($order->shipping_cents)->toBe(0)
        ->and($order->currency)->toBe('USD')->and($order->po_reference)->toBe('NW-PO-9');

    $items = OrderItem::where('order_id', $order->id)->orderBy('id')->get();
    expect($items)->toHaveCount(2)
        ->and($items[0]->only(['product_id', 'part_number', 'name', 'brand', 'unit_price_cents', 'quantity', 'line_total_cents']))
        ->toBe(['product_id' => $w->priced->id, 'part_number' => 'QT-100', 'name' => 'Bearing QT-100', 'brand' => 'SKF', 'unit_price_cents' => 1000, 'quantity' => 10, 'line_total_cents' => 10000])
        ->and($items[1]->only(['product_id', 'part_number', 'name', 'brand', 'unit_price_cents', 'quantity', 'line_total_cents']))
        ->toBe(['product_id' => null, 'part_number' => null, 'name' => 'Taper roller bearing 32208, SKF or equivalent', 'brand' => null, 'unit_price_cents' => 2000, 'quantity' => 4, 'line_total_cents' => 8000]);

    $orderHistory = OrderStatusHistory::where('order_id', $order->id)->get();
    expect($orderHistory)->toHaveCount(1)->and($orderHistory[0]->from_status)->toBeNull()->and($orderHistory[0]->to_status->value)->toBe('pending')
        ->and($orderHistory[0]->actor_user_id)->toBe($w->a1->id);

    $last = QuoteStatusHistory::where('quote_request_id', $this->quote->id)->orderByDesc('id')->first();
    expect($last->from_status->value)->toBe('quoted')->and($last->to_status->value)->toBe('accepted')->and($last->actor_user_id)->toBe($w->a1->id)
        ->and($this->quote->fresh()->status->value)->toBe('accepted');

    // The order shows its origin; the customer sees it in the order endpoints.
    $this->getJson("/api/v1/orders/{$order->order_number}")->assertOk()->assertJsonPath('data.quote_number', $this->n)
        ->assertJsonPath('data.items.1.product_id', null)->assertJsonPath('data.items.1.part_number', null)->assertJsonPath('data.items.1.brand', null);
    $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.quote_number', $this->n);
});

it('takes the PO reference from the body, then the quote, then null', function () {
    $w = $this->w;
    loginAs($w->a1)->postJson("/api/v1/quotes/{$this->n}/accept", ['revision' => 1, 'po_reference' => '  NEW-PO 5 '])->assertOk();
    expect(Order::sole()->po_reference)->toBe('NEW-PO 5');

    $other = makeQuote($w, 'quoted');
    accept($other->quote_number)->assertOk();
    expect(Order::where('quote_request_id', $other->id)->value('po_reference'))->toBeNull();

    $third = makeQuote($w, 'quoted', ['po_reference' => 'KEEP-1']);
    accept($third->quote_number, ['revision' => 1, 'po_reference' => ''])->assertOk();
    expect(Order::where('quote_request_id', $third->id)->value('po_reference'))->toBe('KEEP-1');
});

it('rejects a second accept and leaves one order and no extra history', function () {
    $w = $this->w;
    loginAs($w->a1);
    accept($this->n)->assertOk();
    $history = QuoteStatusHistory::count();
    $orderHistory = OrderStatusHistory::count();

    foreach ([$w->a1, $w->a2] as $user) {
        loginAs($user);
        accept($this->n)->assertStatus(422)->assertJsonValidationErrors('status')->assertJsonPath('errors.status.0', 'This quote has already been accepted.');
    }

    expect(Order::count())->toBe(1)->and(QuoteStatusHistory::count())->toBe($history)->and(OrderStatusHistory::count())->toBe($orderHistory);
});

it('serialises a racing accept: the loser holding a stale model gets 422 and no second order', function () {
    $stale = QuoteRequest::findOrFail($this->quote->id);
    $service = app(AcceptQuote::class);

    $service->handle($this->quote, $this->w->a1, 1, null);
    expect(fn () => $service->handle($stale, $this->w->a2, 1, null))->toThrow(ValidationException::class, 'already been accepted');

    expect(Order::count())->toBe(1);
});

it('refuses staff, other companies and bad bodies in the documented order', function () {
    $w = $this->w;

    loginAs($w->staff);
    accept($this->n)->assertForbidden();
    loginAs($w->admin);
    accept($this->n)->assertForbidden();
    accept('QUO-AAAAA-AAAAA', [])->assertForbidden();

    loginAs($w->b1);
    accept($this->n)->assertNotFound()->assertExactJson(NOT_FOUND);
    // Body validation (422) runs before the lookup (404).
    accept($this->n, [])->assertStatus(422)->assertJsonValidationErrors('revision');
    accept('QUO-AAAAA-AAAAA', [])->assertStatus(422);
    accept('QUO-AAAAA-AAAAA')->assertNotFound();

    expect(Order::count())->toBe(0)->and($this->quote->fresh()->status->value)->toBe('quoted');
});

it('validates the revision and the PO reference', function (array $body, string $key) {
    loginAs($this->w->a1);
    accept($this->n, $body)->assertStatus(422)->assertJsonValidationErrors($key);

    expect(Order::count())->toBe(0)->and($this->quote->fresh()->status->value)->toBe('quoted');
})->with([
    'revision missing' => [[], 'revision'],
    'revision null' => [['revision' => null], 'revision'],
    'revision string' => [['revision' => '1'], 'revision'],
    'revision float' => [['revision' => 1.5], 'revision'],
    'revision zero' => [['revision' => 0], 'revision'],
    'revision above range' => [['revision' => 32768], 'revision'],
    'revision boolean' => [['revision' => true], 'revision'],
    'revision array' => [['revision' => [1]], 'revision'],
    'po reference invalid' => [['revision' => 1, 'po_reference' => '-bad'], 'po_reference'],
    'po reference too long' => [['revision' => 1, 'po_reference' => str_repeat('A', 65)], 'po_reference'],
    'po reference NUL' => [['revision' => 1, 'po_reference' => "A\0"], 'po_reference'],
]);

it('refuses a stale revision with 422 on revision and creates nothing', function () {
    loginAs($this->w->a1);

    accept($this->n, ['revision' => 2])->assertStatus(422)->assertJsonValidationErrors('revision')
        ->assertJsonPath('errors.revision.0', 'This quote was updated. Please review the new prices.');
    expect(Order::count())->toBe(0);
});

it('only accepts a quote that is quoted', function (string $state) {
    $quote = makeQuote($this->w, $state);
    loginAs($this->w->a1);

    accept($quote->quote_number)->assertStatus(422)->assertJsonValidationErrors('status');
    expect(Order::count())->toBe(0);
})->with(['submitted', 'inReview', 'declined', 'expired']);

it('rolls everything back when the order history insert fails', function () {
    OrderStatusHistory::creating(fn () => throw new RuntimeException('boom'));
    $before = QuoteStatusHistory::count();

    try {
        loginAs($this->w->a1);
        accept($this->n)->assertStatus(500);
    } finally {
        OrderStatusHistory::flushEventListeners();
    }

    expect(Order::count())->toBe(0)->and(OrderItem::count())->toBe(0)->and($this->quote->fresh()->status->value)->toBe('quoted')
        ->and(QuoteStatusHistory::count())->toBe($before);
});

it('rolls everything back when the quote history insert fails', function () {
    QuoteStatusHistory::creating(fn () => throw new RuntimeException('boom'));

    try {
        loginAs($this->w->a1);
        accept($this->n)->assertStatus(500);
    } finally {
        QuoteStatusHistory::flushEventListeners();
    }

    expect(Order::count())->toBe(0)->and(OrderItem::count())->toBe(0)->and(OrderStatusHistory::count())->toBe(0)->and($this->quote->fresh()->status->value)->toBe('quoted');
});

it('rolls everything back when the order insert fails', function () {
    Order::creating(fn () => throw new RuntimeException('boom'));

    try {
        loginAs($this->w->a1);
        accept($this->n)->assertStatus(500);
    } finally {
        Order::flushEventListeners();
    }

    expect(Order::count())->toBe(0)->and($this->quote->fresh()->status->value)->toBe('quoted');
});

it('lets the database refuse a second order for the same quote', function () {
    loginAs($this->w->a1);
    accept($this->n)->assertOk();
    $order = Order::sole();

    expect(fn () => DB::transaction(fn () => DB::table('orders')->insert([
        'order_number' => 'ORD-ZZZZZ-ZZZZ2', 'company_id' => $order->company_id, 'created_by_user_id' => $order->created_by_user_id, 'status' => 'pending',
        'currency' => 'USD', 'subtotal_cents' => 0, 'total_cents' => 0, 'quote_request_id' => $this->quote->id, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'orders_quote_request_id_unique');
});

it('ignores a catalog line whose product is gone from the catalog when converting', function () {
    $this->w->priced->forceFill(['price_on_request' => true, 'price_cents' => null, 'stock_status' => 'out_of_stock'])->save();
    loginAs($this->w->a1);

    accept($this->n)->assertOk();

    expect((int) OrderItem::where('order_id', Order::sole()->id)->sum('line_total_cents'))->toBe(18000);
});

it('does not touch the quote lines when accepting', function () {
    loginAs($this->w->a1);
    $before = QuoteItem::orderBy('id')->get()->map->getAttributes()->all();

    accept($this->n)->assertOk();

    expect(QuoteItem::orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
});
