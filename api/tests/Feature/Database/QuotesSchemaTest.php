<?php

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\QuoteAttachment;
use App\Models\QuoteItem;
use App\Models\QuoteRequest;
use App\Models\QuoteStatusHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Insert inside a savepoint so a failed statement does not poison the test transaction. */
function quoteRaw(string $table, array $row): void
{
    DB::transaction(fn () => DB::table($table)->insert($row));
}

function validQuoteRow(QuoteRequest $quote, array $overrides = []): array
{
    return $overrides + [
        'quote_number' => 'QUO-ZZZZZ-ZZZZ2', 'company_id' => $quote->company_id, 'created_by_user_id' => $quote->created_by_user_id, 'status' => 'submitted',
        'currency' => 'USD', 'tax_cents' => 0, 'shipping_cents' => 0, 'created_at' => now(), 'updated_at' => now(),
    ];
}

function validQuoteItemRow(QuoteRequest $quote, array $overrides = []): array
{
    return $overrides + [
        'quote_request_id' => $quote->id, 'position' => 9, 'name' => 'Custom', 'quantity' => 2, 'created_at' => now(), 'updated_at' => now(),
    ];
}

beforeEach(function () {
    Storage::fake('quotes');
    $this->w = quoteWorld();
    $this->quote = QuoteRequest::factory()->forCompany($this->w->a, $this->w->a1)->create(['quote_number' => 'QUO-BCDFG-HJKMN']);
});

it('creates the designed indexes, including the partial one', function () {
    $names = DB::table('pg_indexes')->whereIn('tablename', ['quote_requests', 'quote_items', 'quote_status_history', 'quote_attachments', 'orders'])->pluck('indexdef', 'indexname');

    foreach ([
        'quote_requests_pkey', 'quote_requests_quote_number_unique', 'quote_requests_company_id_created_at_index', 'quote_requests_company_id_status_index',
        'quote_requests_status_created_at_index', 'quote_requests_created_at_index', 'quote_requests_quoted_valid_until_index',
        'quote_items_quote_request_id_position_unique', 'quote_items_quote_request_id_product_id_unique', 'quote_status_history_quote_request_id_id_index',
        'quote_attachments_public_id_unique', 'quote_attachments_storage_path_unique', 'quote_attachments_quote_request_id_id_index', 'orders_quote_request_id_unique',
    ] as $name) {
        expect($names)->toHaveKey($name);
    }
    expect($names['quote_requests_company_id_created_at_index'])->toContain('(company_id, created_at)')
        ->and($names['quote_requests_quoted_valid_until_index'])->toContain('(valid_until)')->toContain("status)::text = 'quoted'");
});

it('rejects duplicate and malformed quote numbers', function (string $number) {
    expect(fn () => quoteRaw('quote_requests', validQuoteRow($this->quote, ['quote_number' => $number])))->toThrow(QueryException::class);
})->with([
    'duplicate' => ['QUO-BCDFG-HJKMN'], 'lowercase' => ['quo-bcdfg-hjkmp'], 'forbidden letter I' => ['QUO-BCDFI-HJKMP'], 'forbidden letter O' => ['QUO-BCDFG-HJKMO'],
    'forbidden digit 0' => ['QUO-BCDFG-HJKM0'], 'forbidden digit 1' => ['QUO-BCDFG-HJKM1'], 'too short' => ['QUO-BCDF-HJKMP'], 'order prefix' => ['ORD-BCDFG-HJKMP'],
]);

it('rejects bad statuses, currencies, notes and inconsistent money on quotes', function (array $bad) {
    expect(fn () => quoteRaw('quote_requests', validQuoteRow($this->quote, ['quote_number' => 'QUO-BCDFG-HJKMP'] + $bad)))->toThrow(QueryException::class);
})->with([
    'bad status' => [['status' => 'paid']], 'order status' => [['status' => 'pending']], 'bad currency' => [['currency' => 'usd']],
    'po too long' => [['po_reference' => str_repeat('A', 65)]], 'customer note too long' => [['customer_note' => str_repeat('n', 2001)]],
    'staff note too long' => [['staff_note' => str_repeat('n', 2001)]], 'internal note too long' => [['internal_note' => str_repeat('n', 2001)]],
    'negative revision' => [['revision' => -1]], 'negative subtotal' => [['subtotal_cents' => -1, 'total_cents' => -1]], 'negative tax' => [['subtotal_cents' => 0, 'tax_cents' => -1, 'total_cents' => -1]],
    'subtotal without total' => [['subtotal_cents' => 100]], 'total without subtotal' => [['total_cents' => 100]], 'total below sum' => [['subtotal_cents' => 100, 'total_cents' => 99]],
    'total above sum' => [['subtotal_cents' => 100, 'total_cents' => 101]], 'tax missing from total' => [['subtotal_cents' => 100, 'tax_cents' => 5, 'total_cents' => 100]],
]);

it('refuses a quoted or accepted quote that is not priced, dated, sent and revised', function (string $status, array $bad) {
    $good = ['status' => $status, 'subtotal_cents' => 100, 'total_cents' => 100, 'valid_until' => '2026-12-31', 'sent_at' => now(), 'revision' => 1];

    quoteRaw('quote_requests', validQuoteRow($this->quote, ['quote_number' => 'QUO-BCDFG-HJKMP'] + $good));
    expect(QuoteRequest::count())->toBe(2);

    expect(fn () => quoteRaw('quote_requests', validQuoteRow($this->quote, ['quote_number' => 'QUO-BCDFG-HJKMQ'] + $bad + $good)))->toThrow(QueryException::class);
})->with(array_merge(...array_map(fn ($status) => [
    "{$status} without total" => [$status, ['subtotal_cents' => null, 'total_cents' => null]],
    "{$status} without valid_until" => [$status, ['valid_until' => null]],
    "{$status} without sent_at" => [$status, ['sent_at' => null]],
    "{$status} with revision 0" => [$status, ['revision' => 0]],
], ['quoted', 'accepted'])));

it('lets draft statuses stay unpriced and expired or declined keep their data', function () {
    foreach (['in_review', 'declined', 'expired'] as $i => $status) {
        quoteRaw('quote_requests', validQuoteRow($this->quote, ['quote_number' => 'QUO-BCDFG-HJKM'.['P', 'Q', 'R'][$i], 'status' => $status]));
    }

    expect(QuoteRequest::count())->toBe(4);
});

it('rejects invalid quote items', function (array $bad) {
    $bad = array_map(fn ($value) => $value === 'PRODUCT' ? makeProduct('SCH-BAD')->id : $value, $bad);

    expect(fn () => quoteRaw('quote_items', validQuoteItemRow($this->quote, $bad)))->toThrow(QueryException::class);
})->with([
    'catalog without part number' => [['product_id' => 'PRODUCT']], 'custom with brand' => [['brand' => 'SKF']], 'custom with part number' => [['part_number' => 'X']],
    'half snapshot' => [['product_id' => 'PRODUCT', 'part_number' => 'X']], 'quantity zero' => [['quantity' => 0]], 'quantity 10000' => [['quantity' => 10000]],
    'position zero' => [['position' => 0]], 'position 51' => [['position' => 51]], 'unit price above the cap' => [['unit_price_cents' => 1000000001, 'line_total_cents' => 2000000002]],
    'negative unit price' => [['unit_price_cents' => -1, 'line_total_cents' => -2]], 'line total mismatch' => [['unit_price_cents' => 10, 'line_total_cents' => 21]],
    'unit without line total' => [['unit_price_cents' => 10]], 'line total without unit' => [['line_total_cents' => 20]],
]);

it('accepts the edges of a quote item and the two line shapes', function () {
    $product = makeProduct('SCH-QI');
    $existing = QuoteItem::where('quote_request_id', $this->quote->id)->count();

    quoteRaw('quote_items', validQuoteItemRow($this->quote, ['position' => 10, 'quantity' => 9999, 'unit_price_cents' => 1000000000, 'line_total_cents' => 9999000000000]));
    quoteRaw('quote_items', validQuoteItemRow($this->quote, ['position' => 11, 'quantity' => 1, 'unit_price_cents' => 0, 'line_total_cents' => 0]));
    quoteRaw('quote_items', validQuoteItemRow($this->quote, ['position' => 12, 'product_id' => $product->id, 'part_number' => 'SCH-QI', 'brand' => 'SKF']));
    // Many custom lines are fine; they are distinct because NULL product ids are distinct.
    quoteRaw('quote_items', validQuoteItemRow($this->quote, ['position' => 13]));
    quoteRaw('quote_items', validQuoteItemRow($this->quote, ['position' => 14]));

    expect(QuoteItem::where('quote_request_id', $this->quote->id)->count())->toBe($existing + 5);
});

it('keeps one line per position and one per catalog product', function () {
    $product = makeProduct('SCH-UQ');
    $row = validQuoteItemRow($this->quote, ['position' => 20, 'product_id' => $product->id, 'part_number' => 'SCH-UQ', 'brand' => 'SKF']);
    quoteRaw('quote_items', $row);

    expect(fn () => quoteRaw('quote_items', $row))->toThrow(QueryException::class)
        ->and(fn () => quoteRaw('quote_items', ['position' => 21] + $row))->toThrow(QueryException::class)
        ->and(fn () => quoteRaw('quote_items', validQuoteItemRow($this->quote, ['position' => 20])))->toThrow(QueryException::class);
});

it('rejects invalid history rows and allows a NULL actor only for expiry', function (array $bad) {
    $row = ['quote_request_id' => $this->quote->id, 'from_status' => 'submitted', 'to_status' => 'in_review', 'actor_user_id' => $this->w->staff->id, 'created_at' => now()];

    expect(fn () => quoteRaw('quote_status_history', $bad + $row))->toThrow(QueryException::class);
})->with([
    'bad from' => [['from_status' => 'paid']], 'bad to' => [['to_status' => 'paid']], 'same status' => [['from_status' => 'submitted', 'to_status' => 'submitted']],
    'null actor on review' => [['actor_user_id' => null]], 'null actor on accepted' => [['to_status' => 'accepted', 'actor_user_id' => null]], 'note too long' => [['note' => str_repeat('n', 501)]],
]);

it('accepts a creation row and a system expiry row', function () {
    $count = QuoteStatusHistory::count();
    quoteRaw('quote_status_history', ['quote_request_id' => $this->quote->id, 'from_status' => null, 'to_status' => 'submitted', 'actor_user_id' => $this->w->a1->id, 'created_at' => now()]);
    quoteRaw('quote_status_history', ['quote_request_id' => $this->quote->id, 'from_status' => 'quoted', 'to_status' => 'expired', 'actor_user_id' => null, 'created_at' => now()]);

    expect(QuoteStatusHistory::count())->toBe($count + 2);
});

it('rejects invalid attachment rows', function (array $bad) {
    $row = [
        'public_id' => (string) Str::uuid(), 'quote_request_id' => $this->quote->id, 'uploaded_by_user_id' => $this->w->a1->id, 'uploaded_by_role' => 'customer',
        'original_name' => 'a.pdf', 'storage_path' => 'quote-attachments/'.bin2hex(random_bytes(16)), 'mime' => 'application/pdf', 'size_bytes' => 10,
        'sha256' => hash('sha256', 'x'), 'created_at' => now(),
    ];
    if (($bad['public_id'] ?? null) === 'PUBLIC_ID') {
        $bad['public_id'] = QuoteAttachment::factory()->create(['quote_request_id' => $this->quote->id])->public_id;
    }

    expect(fn () => quoteRaw('quote_attachments', $bad + $row))->toThrow(QueryException::class);
})->with([
    'mime outside the allowlist' => [['mime' => 'text/html']], 'svg' => [['mime' => 'image/svg+xml']], 'zip' => [['mime' => 'application/zip']],
    'size zero' => [['size_bytes' => 0]], 'size above 10 MiB' => [['size_bytes' => 10485761]], 'path with an extension' => [['storage_path' => 'quote-attachments/'.str_repeat('a', 32).'.pdf']],
    'path traversal' => [['storage_path' => 'quote-attachments/../../x']], 'path uppercase hex' => [['storage_path' => 'quote-attachments/'.str_repeat('A', 32)]],
    'path too short' => [['storage_path' => 'quote-attachments/abc']], 'other directory' => [['storage_path' => 'other/'.str_repeat('a', 32)]],
    'sha256 too short' => [['sha256' => 'abc']], 'sha256 uppercase' => [['sha256' => strtoupper(hash('sha256', 'x'))]], 'bad role' => [['uploaded_by_role' => 'admin']],
    'duplicate public id' => [['public_id' => 'PUBLIC_ID']],
]);

it('restricts deleting anything a quote depends on or that depends on a quote', function () {
    $product = makeProduct('SCH-FK');
    QuoteItem::factory()->catalog($product)->create(['quote_request_id' => $this->quote->id, 'position' => 30]);
    $attachment = QuoteAttachment::factory()->create(['quote_request_id' => $this->quote->id]);

    foreach ([
        'company with quotes' => fn () => Company::whereKey($this->quote->company_id)->delete(),
        'creator of a quote' => fn () => User::whereKey($this->quote->created_by_user_id)->delete(),
        'product on a quote line' => fn () => Product::whereKey($product->id)->delete(),
        'quote with lines' => fn () => QuoteRequest::whereKey($this->quote->id)->delete(),
        'uploader of an attachment' => fn () => User::whereKey($attachment->uploaded_by_user_id)->delete(),
    ] as $delete) {
        expect(fn () => DB::transaction($delete))->toThrow(QueryException::class, 'violates foreign key');
    }

    $accepted = QuoteRequest::factory()->forCompany($this->w->a, $this->w->a1)->accepted()->create();
    expect(fn () => DB::transaction(fn () => QuoteRequest::whereKey($accepted->id)->delete()))->toThrow(QueryException::class);
});

it('restricts deleting the actor of a history row', function () {
    quoteRaw('quote_status_history', ['quote_request_id' => $this->quote->id, 'from_status' => 'submitted', 'to_status' => 'in_review', 'actor_user_id' => $this->w->staff->id, 'created_at' => now()]);

    expect(fn () => DB::transaction(fn () => User::whereKey($this->w->staff->id)->delete()))->toThrow(QueryException::class);
});

it('allows one order per quote and custom order lines, but refuses half-null order lines', function () {
    $order = Order::factory()->forCompany($this->w->a, $this->w->a1)->create();
    $row = fn (string $number, array $extra = []) => $extra + [
        'order_number' => $number, 'company_id' => $order->company_id, 'created_by_user_id' => $order->created_by_user_id, 'status' => 'pending', 'currency' => 'USD',
        'subtotal_cents' => 0, 'total_cents' => 0, 'created_at' => now(), 'updated_at' => now(),
    ];

    // Cart orders have no quote and any number of them is fine.
    quoteRaw('orders', $row('ORD-ZZZZZ-ZZZZ2'));
    quoteRaw('orders', $row('ORD-ZZZZZ-ZZZZ3'));
    quoteRaw('orders', $row('ORD-ZZZZZ-ZZZZ4', ['quote_request_id' => $this->quote->id]));
    expect(fn () => quoteRaw('orders', $row('ORD-ZZZZZ-ZZZZ5', ['quote_request_id' => $this->quote->id])))->toThrow(QueryException::class, 'orders_quote_request_id_unique')
        ->and(fn () => quoteRaw('orders', $row('ORD-ZZZZZ-ZZZZ6', ['quote_request_id' => 99999999])))->toThrow(QueryException::class);

    $line = fn (array $extra) => $extra + ['order_id' => $order->id, 'name' => 'x', 'unit_price_cents' => 10, 'quantity' => 2, 'line_total_cents' => 20, 'created_at' => now(), 'updated_at' => now()];
    $product = makeProduct('SCH-OI');
    quoteRaw('order_items', $line([]));
    quoteRaw('order_items', $line([]));
    quoteRaw('order_items', $line(['product_id' => $product->id, 'part_number' => 'SCH-OI', 'brand' => 'SKF']));

    foreach ([['product_id' => $product->id], ['part_number' => 'X'], ['brand' => 'SKF'], ['product_id' => $product->id, 'part_number' => 'X']] as $half) {
        expect(fn () => quoteRaw('order_items', $line($half)))->toThrow(QueryException::class);
    }
    // The old order item rules still hold.
    expect(fn () => quoteRaw('order_items', $line(['quantity' => 0, 'line_total_cents' => 0])))->toThrow(QueryException::class)
        ->and(fn () => quoteRaw('order_items', $line(['line_total_cents' => 21])))->toThrow(QueryException::class)
        ->and(OrderItem::where('order_id', $order->id)->whereNull('product_id')->count())->toBe(2);
});
