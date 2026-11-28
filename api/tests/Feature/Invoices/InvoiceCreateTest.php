<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\QuoteRequest;
use App\Services\InvoicePdf;
use App\Services\IssueInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProductionConfig;

beforeEach(function () {
    $this->w = orderWorld();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
    app()->detectEnvironment(fn () => 'testing');
});

/** Nothing was written and no number consumed. */
function expectNothingIssued(): void
{
    expect(Invoice::count())->toBe(0)->and(DB::table('invoice_items')->count())->toBe(0)->and(DB::table('invoice_status_history')->count())->toBe(0)
        ->and(DB::table('invoice_number_counters')->count())->toBe(0);
}

// ---------------------------------------------------------------- the happy path and its shape

it('creates an open invoice from a confirmed order with the exact detail shape', function () {
    $order = orderWith($this->w->a, [[1250, 3], [333, 10]], 'confirmed', ['po_reference' => 'PO-4711/A']);

    $response = issueInvoiceAs($this->w->staff, $order)->assertCreated();
    $data = $response->json('data');

    expect(array_keys($data))->toBe(INVOICE_DETAIL_KEYS)
        ->and($data['id'])->toMatch(UUID_V4)
        ->and($data['number'])->toBe('INV-2026-000001')
        ->and($data['status'])->toBe('open')->and($data['days_overdue'])->toBe(0)
        ->and($data['currency'])->toBe($order->currency)->and($data['order_number'])->toBe($order->order_number)->and($data['po_reference'])->toBe('PO-4711/A')
        ->and($data['issue_date'])->toBe('2026-11-26')->and($data['due_date'])->toBe('2026-12-26')->and($data['paid_on'])->toBeNull()
        ->and($data['subtotal_cents'])->toBe(7080)->and($data['vat_rate_bp'])->toBe(0)->and($data['tax_cents'])->toBe(0)->and($data['total_cents'])->toBe(7080)
        ->and($data['line_count'])->toBe(2)
        ->and($data['company'])->toBe(['id' => $this->w->a->id, 'name' => $this->w->a->name])
        ->and($data['created_at'])->toBe('2026-11-26T10:00:00Z')
        ->and($data['allowed_actions'])->toBe(['download', 'mark_paid', 'void'])
        ->and($data['history'])->toBe([['from_status' => null, 'to_status' => 'open', 'actor' => ['name' => $this->w->staff->name, 'role' => 'staff'], 'note' => null, 'created_at' => '2026-11-26T10:00:00Z']])
        ->and($data['seller'])->toBe(testInvoicingConfig()['seller'] + ['vat_label' => 'VAT'])
        ->and($data['buyer'])->toBe(['name' => $this->w->a->name, 'address_lines' => preg_split('/\n/', $this->w->a->billing_address), 'tax_id' => $this->w->a->tax_id])
        ->and(array_column($data['items'], 'position'))->toBe([1, 2]);

    // Nothing in the body is internal.
    expect(json_encode($data))->not->toContain('"company_id"')->and(json_encode($data))->not->toContain('"user_id"');
    $this->assertDatabaseHas('invoices', ['public_id' => $data['id'], 'company_id' => $this->w->a->id, 'order_id' => $order->id, 'created_by_user_id' => $this->w->staff->id]);
});

it('lets an admin issue too and records the admin as actor', function () {
    $response = issueInvoiceAs($this->w->admin, orderWith($this->w->a, [[1000, 1]]))->assertCreated();

    expect($response->json('data.history.0.actor'))->toBe(['name' => $this->w->admin->name, 'role' => 'admin']);
});

it('invoices confirmed, shipped and delivered orders and refuses pending and cancelled with 422 on order', function (string $status, bool $allowed) {
    $order = orderWith($this->w->a, [[1000, 1]], $status);
    $response = issueInvoiceAs($this->w->staff, $order);

    if ($allowed) {
        $response->assertCreated();
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('order');
        expectNothingIssued();
    }
})->with([['confirmed', true], ['shipped', true], ['delivered', true], ['pending', false], ['cancelled', false]]);

it('honours a changed list of eligible order statuses', function () {
    config(['invoicing.eligible_order_statuses' => ['pending']]);

    issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]], 'pending'))->assertCreated();
    issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]], 'confirmed'))->assertUnprocessable()->assertJsonValidationErrors('order');
});

// ---------------------------------------------------------------- totals, VAT, snapshots

it('computes the F7 totals server-side: rounded once on the subtotal', function () {
    config(['invoicing.vat_rate_bp' => 1900]);
    $order = orderWith($this->w->a, [[1250, 3], [333, 10], [99999, 1]]);

    $data = issueInvoiceAs($this->w->staff, $order)->assertCreated()->json('data');

    expect($data['subtotal_cents'])->toBe(107079)->and($data['vat_rate_bp'])->toBe(1900)->and($data['tax_cents'])->toBe(20345)->and($data['total_cents'])->toBe(127424)
        ->and(array_column($data['items'], 'line_total_cents'))->toBe([3750, 3330, 99999]);
    $this->assertDatabaseHas('invoices', ['public_id' => $data['id'], 'subtotal_cents' => 107079, 'tax_cents' => 20345, 'total_cents' => 127424, 'vat_rate_bp' => 1900]);
    expect((int) DB::table('invoice_items')->where('invoice_id', Invoice::where('public_id', $data['id'])->value('id'))->sum('line_total_cents'))->toBe(107079);
});

it('equals the order total with the default rate of 0 and the order subtotal for any rate', function () {
    $order = orderWith($this->w->a, [[1250, 3], [333, 10]]);

    $data = issueInvoiceAs($this->w->staff, $order)->assertCreated()->json('data');

    expect($data['total_cents'])->toBe($order->total_cents)->and($data['subtotal_cents'])->toBe($order->subtotal_cents);
});

it('snapshots rate, tax, seller, buyer and lines: later edits change nothing, neither JSON nor PDF HTML', function () {
    config(['invoicing.vat_rate_bp' => 1900]);
    $product = Product::factory()->create(['part_number' => 'SNAP-1', 'name' => 'Snapshot bearing', 'price_cents' => 1250, 'category_id' => category()->id]);
    $order = Order::factory()->confirmed()->forCompany($this->w->a)->withLines([[$product, 4]])->create();
    $created = issueInvoiceAs($this->w->staff, $order)->assertCreated()->json('data');
    $id = $created['id'];
    $html = fn () => app(InvoicePdf::class)->html(Invoice::with('items')->where('public_id', $id)->first());
    $before = $html();

    config(['invoicing.vat_rate_bp' => 500, 'invoicing.seller.name' => 'Changed Seller AG', 'invoicing.vat_label' => 'GST']);
    $product->forceFill(['price_cents' => 9999, 'name' => 'Renamed'])->save();
    // order_items are append-only in the database (Phase 7 trigger): lift it for this one test to prove the INVOICE snapshot does not read the order lines again.
    DB::statement('ALTER TABLE order_items DISABLE TRIGGER order_items_append_only');
    DB::table('order_items')->where('order_id', $order->id)->update(['name' => 'Edited line', 'unit_price_cents' => 1, 'line_total_cents' => 4]);
    DB::statement('ALTER TABLE order_items ENABLE TRIGGER order_items_append_only');
    $this->w->a->forceFill(['name' => 'Renamed Company', 'billing_address' => 'Elsewhere 1', 'tax_id' => 'NEW'])->save();

    $after = $this->actingAs($this->w->staff)->getJson("/api/v1/invoices/{$id}")->assertOk()->json('data');

    expect($after)->toBe($created)->and($html())->toBe($before)
        ->and($after['company']['name'])->not->toBe('Renamed Company')->and($after['items'][0]['name'])->toBe('Snapshot bearing');
});

it('copies free-text quote lines with null part number and brand', function () {
    $quote = QuoteRequest::factory()->forCompany($this->w->a, $this->w->a1)->accepted()
        ->withLines([['Taper roller bearing 32208, SKF or equivalent', 4, 2500], [makeProduct('QL-1', ['price_cents' => 1000]), 2, 1000]])->create();
    $order = Order::where('quote_request_id', $quote->id)->firstOrFail();
    $order->forceFill(['status' => 'confirmed'])->save();

    $items = issueInvoiceAs($this->w->staff, $order)->assertCreated()->json('data.items');

    expect($items[0])->toBe(['position' => 1, 'part_number' => null, 'name' => 'Taper roller bearing 32208, SKF or equivalent', 'brand' => null, 'quantity' => 4, 'unit_price_cents' => 2500, 'line_total_cents' => 10000])
        ->and($items[1]['part_number'])->toBe('QL-1')->and($items[1]['brand'])->toBe('SKF');
});

it('ignores every forged body field and reads only due_date', function () {
    $order = orderWith($this->w->a, [[1000, 2]], 'confirmed', ['po_reference' => 'REAL-PO']);

    $response = issueInvoiceAs($this->w->staff, $order, [
        'company_id' => $this->w->b->id, 'order_id' => 999999, 'number' => 'INV-1999-000001', 'id' => 'forged', 'status' => 'paid', 'issue_date' => '2020-01-01',
        'subtotal_cents' => 1, 'tax_cents' => 1, 'total_cents' => 1, 'vat_rate_bp' => 5000, 'seller' => ['name' => 'Forged'], 'buyer' => ['name' => 'Forged'],
        'items' => [['name' => 'Forged', 'unit_price_cents' => 1, 'quantity' => 1]], 'currency' => 'EUR', 'po_reference' => 'FORGED', 'paid_on' => '2026-11-20',
        'created_by_user_id' => $this->w->admin->id, 'due_date' => '2026-12-01',
    ])->assertCreated();
    $data = $response->json('data');

    expect($data['number'])->toBe('INV-2026-000001')->and($data['status'])->toBe('open')->and($data['issue_date'])->toBe('2026-11-26')->and($data['due_date'])->toBe('2026-12-01')
        ->and($data['total_cents'])->toBe(2000)->and($data['vat_rate_bp'])->toBe(0)->and($data['po_reference'])->toBe('REAL-PO')->and($data['paid_on'])->toBeNull()
        ->and($data['seller']['name'])->toBe('Test Seller GmbH')->and($data['buyer']['name'])->toBe($this->w->a->name)->and($data['currency'])->toBe($order->currency)
        ->and($data['items'])->toHaveCount(1);
    $this->assertDatabaseHas('invoices', ['company_id' => $this->w->a->id, 'order_id' => $order->id, 'created_by_user_id' => $this->w->staff->id]);
});

// ---------------------------------------------------------------- one active invoice per order

it('answers a second create for the same order with 422 on order and consumes no number', function () {
    $order = orderWith($this->w->a, [[1000, 1]]);
    issueInvoiceAs($this->w->staff, $order)->assertCreated();

    issueInvoiceAs($this->w->staff, $order)->assertUnprocessable()->assertJsonPath('errors.order.0', 'This order already has an invoice.');
    $next = issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertCreated();

    expect(Invoice::count())->toBe(2)->and($next->json('data.number'))->toBe('INV-2026-000002')
        ->and((int) DB::table('invoice_number_counters')->value('last_value'))->toBe(2);
});

it('simulates two parallel creates for one order: the second sees the first under the order lock', function () {
    $order = orderWith($this->w->a, [[1000, 1]]);
    $first = app(IssueInvoice::class)->handle($this->w->staff, $order, null);

    // The "parallel" request arrives with a stale Order model fetched before the first commit: the service re-reads under lock.
    expect(fn () => app(IssueInvoice::class)->handle($this->w->staff, $order, null))->toThrow(ValidationException::class);
    expect(Invoice::where('order_id', $order->id)->count())->toBe(1)->and($first->number)->toBe('INV-2026-000001');
});

it('allows a new invoice for the same order after the first was voided, with the next number', function () {
    $order = orderWith($this->w->a, [[1000, 1]]);
    $first = issueInvoiceAs($this->w->staff, $order)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/invoices/{$first}/void", ['reason' => 'Wrong address'])->assertOk();

    $second = issueInvoiceAs($this->w->staff, $order)->assertCreated();

    expect($second->json('data.number'))->toBe('INV-2026-000002')->and(Invoice::where('order_id', $order->id)->count())->toBe(2);
});

it('lets the database enforce one non-void invoice per order, while void plus open is allowed', function () {
    $open = makeInvoice($this->w->a);
    $order = Order::findOrFail($open->order_id);

    violates(fn () => DB::table('invoices')->insert(invoiceRow($open, ['public_id' => (string) Str::uuid(), 'number' => 'INV-2026-000099', 'number_seq' => 99])), 'invoices_one_active_per_order');

    $void = Invoice::factory()->forOrder($order)->void();
    // An open invoice already exists for this order, so a second non-void one is impossible; a void one is fine.
    expect(fn () => DB::transaction(fn () => Invoice::factory()->forOrder($order)->open()->create()))->toThrow(QueryException::class);
    expect($void->create()->status->value)->toBe('void');
});

// ---------------------------------------------------------------- refusals

it('refuses a company without a billing address (422 company) and writes nothing', function () {
    $this->w->a->forceFill(['billing_address' => null])->save();

    issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertUnprocessable()->assertJsonValidationErrors('company');
    expectNothingIssued();
});

it('refuses an order with tax or shipping, and an order without lines (422 order)', function () {
    $withTax = orderWith($this->w->a, [[1000, 1]]);
    DB::table('orders')->where('id', $withTax->id)->update(['tax_cents' => 5, 'total_cents' => 1005]);
    $withShipping = orderWith($this->w->a, [[1000, 1]]);
    DB::table('orders')->where('id', $withShipping->id)->update(['shipping_cents' => 5, 'total_cents' => 1005]);
    $empty = Order::factory()->confirmed()->forCompany($this->w->a)->withLines([])->create();

    foreach ([$withTax, $withShipping, $empty] as $order) {
        issueInvoiceAs($this->w->staff, $order)->assertUnprocessable()->assertJsonValidationErrors('order');
    }
    expectNothingIssued();
});

it('fails loudly and writes nothing when an order line total does not add up to its subtotal', function () {
    $order = orderWith($this->w->a, [[1000, 2]]);
    DB::table('orders')->where('id', $order->id)->update(['subtotal_cents' => 1, 'total_cents' => 1]);

    issueInvoiceAs($this->w->staff, $order)->assertStatus(500);

    expectNothingIssued();
});

it('answers a malformed JSON body with 422 on body', function (string $raw) {
    $order = orderWith($this->w->a, [[1000, 1]]);

    $this->actingAs($this->w->staff)->call('POST', "/api/v1/orders/{$order->order_number}/invoice", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:5173'], $raw)
        ->assertUnprocessable()->assertJsonValidationErrors('body');
    expectNothingIssued();
})->with(['{"due_date": ', 'not json', '5', '"x"', 'null']);

it('accepts an empty body, {} and []', function () {
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:5173'];
    $this->actingAs($this->w->staff);

    foreach (['', '{}', '[]'] as $raw) {
        $order = orderWith($this->w->a, [[1000, 1]]);
        $this->call('POST', "/api/v1/orders/{$order->order_number}/invoice", [], [], [], $server, $raw)->assertCreated();
    }
});

// ---------------------------------------------------------------- due date

it('computes the due date as the issue date plus the configured terms, optionally overridden within bounds', function () {
    expect(issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->json('data.due_date'))->toBe('2026-12-26');

    config(['invoicing.payment_terms_days' => 14]);
    expect(issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->json('data.due_date'))->toBe('2026-12-10');

    config(['invoicing.payment_terms_days' => 0]);
    expect(issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->json('data.due_date'))->toBe('2026-11-26');
});

it('accepts a due date override from today up to today plus 120 days and treats null as the default', function (?string $due, string $expected) {
    $data = issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]), ['due_date' => $due])->assertCreated()->json('data');

    expect($data['due_date'])->toBe($expected);
})->with([['2026-11-26', '2026-11-26'], ['2027-03-26', '2027-03-26'], [null, '2026-12-26']]);

it('rejects a bad due date override with 422 on due_date and consumes no number', function (mixed $due) {
    issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]), ['due_date' => $due])->assertUnprocessable()->assertJsonValidationErrors('due_date');

    expectNothingIssued();
})->with([
    'yesterday' => '2026-11-25', 'today + 121' => '2027-03-27', 'impossible date' => '2026-02-30', 'datetime' => '2026-12-01T10:00:00Z', 'datetime with space' => '2026-12-01 10:00:00',
    'number' => 20261201, 'array' => [['2026-12-01']], 'wrong format' => '01.12.2026', 'text' => 'tomorrow', 'boolean' => true, 'interior NUL' => "2026-12-0\x001",
]);

it('re-checks the due date override against the real issue date inside the transaction', function () {
    $order = orderWith($this->w->a, [[1000, 1]]);

    // Midnight passed between validation and the transaction: the override is now in the past for the real issue date.
    expect(fn () => app(IssueInvoice::class)->handle($this->w->staff, $order, '2026-11-25'))->toThrow(ValidationException::class);
    expectNothingIssued();
});

// ---------------------------------------------------------------- configuration guards

it('refuses to issue when the VAT rate or payment terms are invalid, consuming nothing', function (string $key, mixed $value) {
    config([$key => $value]);

    issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertUnprocessable()->assertJsonPath('errors.invoice.0', 'Invoicing is not configured.');

    expectNothingIssued();
})->with([
    ['invoicing.vat_rate_bp', -1], ['invoicing.vat_rate_bp', 5001], ['invoicing.vat_rate_bp', 'abc'], ['invoicing.vat_rate_bp', 19.5],
    ['invoicing.payment_terms_days', -1], ['invoicing.payment_terms_days', 121], ['invoicing.payment_terms_days', 'x'],
]);

it('refuses the placeholder seller name in production only', function () {
    config(['invoicing.seller.name' => 'PLACEHOLDER SELLER NAME']);
    $order = orderWith($this->w->a, [[1000, 1]]);

    issueInvoiceAs($this->w->staff, $order)->assertCreated();

    // CSRF is only skipped when the environment is "testing"; this test flips it, so skip the middleware instead.
    $this->withoutMiddleware(ValidateCsrfToken::class);
    ProductionConfig::applyForTestClient('production');
    issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertUnprocessable()->assertJsonValidationErrors('invoice');
    expect(Invoice::count())->toBe(1);

    config(['invoicing.seller.name' => 'Real Seller GmbH']);
    issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertCreated();
});

it('refuses an oversized seller address or footer', function () {
    config(['invoicing.seller.address_lines' => array_fill(0, 7, 'Line')]);
    issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertUnprocessable()->assertJsonValidationErrors('invoice');

    config(['invoicing.seller.address_lines' => ['Fine'], 'invoicing.seller.footer' => str_repeat('x', 301)]);
    issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertUnprocessable()->assertJsonValidationErrors('invoice');
    expectNothingIssued();
});

it('uses the same number of INSERT statements for 1 and 50 order lines (bulk item insert)', function () {
    $inserts = function (int $lines) {
        $order = Order::factory()->confirmed()->forCompany($this->w->a)->withLines(array_map(fn ($i) => [Product::factory()->create(), 1], range(1, $lines)))->create();
        DB::enableQueryLog();
        issueInvoiceAs($this->w->staff, $order)->assertCreated();
        $count = count(array_filter(DB::getQueryLog(), fn ($q) => str_starts_with(strtolower($q['query']), 'insert')));
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    };

    expect($inserts(50))->toBe($inserts(1));
});

it('copies 100 order lines into 100 numbered invoice items in order', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->withLines(100)->create();

    expect(InvoiceItem::where('invoice_id', $invoice->id)->orderBy('position')->pluck('position')->all())->toBe(range(1, 100))
        ->and($invoice->subtotal_cents)->toBe(array_sum(array_map(fn ($i) => 2 * (1000 + $i), range(1, 100))));
});
