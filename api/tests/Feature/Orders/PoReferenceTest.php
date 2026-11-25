<?php

use App\Models\CartItem;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->w = orderWorld();
    $this->p = makeProduct('PO-1', ['price_cents' => 1000]);
    fillCart($this->w->a1, [[$this->p, 1]]);
    loginAs($this->w->a1);
});

it('stores a valid PO reference trimmed and returns it on create, detail and list', function (string $sent, string $stored) {
    $number = $this->postJson('/api/v1/orders', ['po_reference' => $sent])->assertCreated()->assertJsonPath('data.po_reference', $stored)->json('data.number');

    expect(Order::where('order_number', $number)->value('po_reference'))->toBe($stored);
    $this->getJson("/api/v1/orders/{$number}")->assertJsonPath('data.po_reference', $stored);
    $this->getJson('/api/v1/orders')->assertJsonPath('data.0.po_reference', $stored);
})->with([
    'plain' => ['PO-4711', 'PO-4711'], 'trimmed' => ['  PO 12/34 #5  ', 'PO 12/34 #5'], 'single letter' => ['A', 'A'], 'single digit' => ['7', '7'],
    'all punctuation' => ['a1 . - _ / #', 'a1 . - _ / #'], 'inner double space' => ['a  b', 'a  b'], 'tab and newline trimmed' => ["\tPO 1\n", 'PO 1'], 'case preserved' => ['po-Ab/9', 'po-Ab/9'],
    'zero' => ['0', '0'], 'nbsp at the ends' => ["\u{00A0}PO 1\u{00A0}", 'PO 1'], 'unicode spaces at the ends' => ["\u{2003}PO 1\u{3000}", 'PO 1'],
    'CR LF and VT at the ends' => ["\r\x0BPO 1\n", 'PO 1'], '64 after trim' => [' '.str_repeat('A', 64).' ', str_repeat('A', 64)], 'slash and hash' => ['PO-4711/A #2', 'PO-4711/A #2'],
    '64 characters' => [str_repeat('A', 64), str_repeat('A', 64)],
]);

it('stores NULL for a missing, null, empty or blank PO reference', function (array $body) {
    $this->postJson('/api/v1/orders', $body)->assertCreated()->assertJsonPath('data.po_reference', null);

    expect(Order::value('po_reference'))->toBeNull();
})->with([[[]], [['po_reference' => null]], [['po_reference' => '']], [['po_reference' => '   ']]]);

it('rejects an invalid PO reference with 422 on po_reference and creates nothing', function (mixed $value) {
    $response = $this->postJson('/api/v1/orders', ['po_reference' => $value])->assertStatus(422)->assertJsonValidationErrors('po_reference');

    expect(array_keys($response->json('errors')))->toBe(['po_reference']);

    expect(Order::count())->toBe(0)->and(CartItem::count())->toBe(1);
})->with([
    '65 characters' => [str_repeat('A', 65)],
    'leading dash' => ['-ABC'], 'leading dot' => ['.ABC'], 'leading underscore' => ['_ABC'], 'leading slash' => ['/ABC'], 'leading hash' => ['#12'],
    'semicolon' => ['a;b'], 'quote' => ["a'b"], 'double quote' => ['a"b'], 'angle' => ['<b>'], 'percent' => ['a%b'], 'backslash' => ['a\\b'],
    'ampersand' => ['a&b'], 'plus' => ['a+b'], 'comma' => ['a,b'], 'parens' => ['a(b)'], 'emoji' => ["a\u{1F600}"],
    'u umlaut' => ['Müller 7'], 'A umlaut' => ['Ä-12'], 'leading equals' => ['=SUM(A1)'], 'leading minus digit' => ['-1'], 'leading at' => ['@x'], 'leading plus' => ['+1'],
    'script' => ['<script>alert(1)</script>'], 'trailing NUL' => ["AB\0"], 'newline then char' => ["AB\nC"], 'nbsp inside' => ["a\u{00A0}b"],
    'tab' => ["a\tb"], 'NUL only' => ["\0"], 'NUL and space' => ["\0 "], 'space and NUL' => [" \0"], 'NUL and whitespace' => ["\0\t\n"],
    'two NULs' => ["\0\0"], 'leading NUL' => ["\0AB"], 'DEL at the end' => ["AB\x7F"], 'DEL at the start' => ["\x7FAB"], 'DEL only' => ["\x7F"],
    'NEL at the end' => ["AB\u{0085}"], 'NEL at the start' => ["\u{0085}AB"], 'zero width space at the end' => ["AB\u{200B}"], 'zero width space only' => ["\u{200B}"],
    'escape char' => ["AB\x1B"], 'newline' => ["a\nb"], 'NUL' => ["a\0b"], 'sql' => ["x'; DROP TABLE orders;--"],
    'array' => [['A']], 'empty array' => [[]], 'object' => [['a' => 'b']], 'number' => [123], 'true' => [true],
]);

it('rejects invalid UTF-8 in a form encoded PO reference with 422', function () {
    $this->call('POST', '/api/v1/orders', ['po_reference' => "ab\xFFc"], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:5173'])
        ->assertStatus(422)->assertJsonValidationErrors('po_reference');

    expect(Order::count())->toBe(0);
});

it('keeps the PO reference immutable after creation', function () {
    CartItem::query()->delete();
    $order = placeOrderAs($this->w->a1, [[$this->p, 1]], ['po_reference' => 'FIRST-1']);
    $n = $order->order_number;

    loginAs($this->w->staff)->patchJson("/api/v1/orders/{$n}/status", ['status' => 'confirmed', 'po_reference' => 'HACK'])->assertOk()->assertJsonPath('data.po_reference', 'FIRST-1');
    loginAs($this->w->staff)->postJson("/api/v1/orders/{$n}/cancel", ['po_reference' => 'HACK'])->assertOk()->assertJsonPath('data.po_reference', 'FIRST-1');
    $this->patchJson("/api/v1/orders/{$n}", ['po_reference' => 'HACK'])->assertStatus(405);
    $this->putJson("/api/v1/orders/{$n}", ['po_reference' => 'HACK'])->assertStatus(405);

    expect($order->fresh()->po_reference)->toBe('FIRST-1');
});

it('enforces the length CHECK and the column type in the database', function () {
    $row = Order::factory()->forCompany($this->w->a, $this->w->a1)->make()->getAttributes();
    $insert = fn (string $number, string $po) => DB::transaction(fn () => DB::table('orders')->insert([
        'order_number' => $number, 'company_id' => $row['company_id'],
        'created_by_user_id' => $row['created_by_user_id'], 'status' => 'pending', 'currency' => 'USD', 'po_reference' => $po,
        'subtotal_cents' => 0, 'total_cents' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]));

    $insert('ORD-BCDFG-HJKMN', str_repeat('A', 64));
    expect(fn () => $insert('ORD-BCDFG-HJKMP', str_repeat('A', 65)))->toThrow(QueryException::class, 'value too long');

    expect(DB::selectOne("select character_maximum_length l, is_nullable n from information_schema.columns where table_name = 'orders' and column_name = 'po_reference'"))
        ->l->toBe(64)->n->toBe('YES');
    expect(DB::selectOne("select count(*) c from pg_constraint where conname = 'orders_po_reference_length'")->c)->toBe(1);
});

it('validates the PO reference before the cart is read, and the role check still comes first', function () {
    CartItem::query()->delete(); // empty cart

    $bad = $this->postJson('/api/v1/orders', ['po_reference' => '-bad'])->assertStatus(422);
    expect(array_keys($bad->json('errors')))->toBe(['po_reference']);

    $this->postJson('/api/v1/orders', ['po_reference' => 'PO-1'])->assertStatus(422)->assertJsonValidationErrors('cart');
    expect(Order::count())->toBe(0);

    loginAs($this->w->staff)->postJson('/api/v1/orders', ['po_reference' => '-bad'])->assertForbidden();
});

it('counts a rejected PO reference toward the orders-create limiter', function () {
    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/orders', ['po_reference' => '-bad'])->assertStatus(422);
    }

    $this->postJson('/api/v1/orders', ['po_reference' => 'PO-1'])->assertStatus(429);
});

it('searches by PO case-insensitively, finds order numbers too, and never leaks another company', function () {
    CartItem::query()->delete();
    $mine = Order::factory()->forCompany($this->w->a, $this->w->a1)->create(['order_number' => 'ORD-BCDFG-HJKMN', 'po_reference' => 'PO-4711/A']);
    $number = Order::factory()->forCompany($this->w->a, $this->w->a1)->create(['order_number' => 'ORD-PQRST-VWXYZ', 'po_reference' => null]);
    $theirs = Order::factory()->forCompany($this->w->b, $this->w->b1)->create(['order_number' => 'ORD-K7Q2M-X9PA3', 'po_reference' => 'SECRET-77']);

    $found = fn (string $q) => array_column($this->getJson('/api/v1/orders?q='.rawurlencode($q))->assertOk()->json('data'), 'number');

    expect($found('po-47'))->toBe([$mine->order_number])->and($found('4711/a'))->toBe([$mine->order_number])
        ->and($found('VWXYZ'))->toBe([$number->order_number])->and($found('null'))->toBe([])->and($found('none'))->toBe([])
        ->and($found('SECRET'))->toBe([])->and($found('77'))->toBe([])->and($found('SECRET-77'))->toBe([])
        ->and($this->getJson('/api/v1/orders?q=SECRET&status=pending&from=2020-01-01&to=2030-01-01')->json('meta.total'))->toBe(0);

    loginAs($this->w->staff);
    expect($found('SECRET'))->toBe([$theirs->order_number]);
});
