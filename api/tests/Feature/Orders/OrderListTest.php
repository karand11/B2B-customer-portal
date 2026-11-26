<?php

use App\Models\Order;
use App\Models\Product;

/** Order of company A with fixed number, status, time, price and optional PO (no random data involved in any assertion). */
function mkOrder($w, string $number, string $status, string $createdAt, int $price, ?string $po = null): Order
{
    return Order::factory()->forCompany($w->a, $w->a1)->{$status}()->createdAt($createdAt)
        ->withLines([[Product::factory()->create(['price_cents' => $price]), 1]])
        ->create(['order_number' => $number, 'po_reference' => $po]);
}

function numbers($response): array
{
    return array_column($response->json('data'), 'number');
}

beforeEach(function () {
    $this->w = orderWorld();
    // Fixed numbers use the real alphabet but share no 3-char fragment that a search below could hit by accident.
    $this->o1 = mkOrder($this->w, 'ORD-K7Q2M-X9PA3', 'pending', '2026-10-31 23:59:59', 500, 'PO-4711');
    $this->o2 = mkOrder($this->w, 'ORD-BCDFG-HJKMN', 'confirmed', '2026-11-01 00:00:00', 300);
    $this->o3 = mkOrder($this->w, 'ORD-PQRST-VWXYZ', 'shipped', '2026-11-05 12:00:00', 300, 'Job 12/B_7');
    $this->o4 = mkOrder($this->w, 'ORD-22345-67892', 'cancelled', '2026-09-15 08:00:00', 100);
    loginAs($this->w->a1);
});

it('returns the exact list shape with six meta keys and newest first', function () {
    $response = $this->getJson('/api/v1/orders')->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'meta'])
        ->and(array_keys($response->json('meta')))->toBe(['current_page', 'last_page', 'per_page', 'total', 'from', 'to'])
        ->and($response->json('meta'))->toBe(['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 4, 'from' => 1, 'to' => 4])
        ->and(numbers($response))->toBe([$this->o3->order_number, $this->o2->order_number, $this->o1->order_number, $this->o4->order_number])
        ->and(array_keys($response->json('data.0')))->toBe([
            'number', 'status', 'currency', 'subtotal_cents', 'tax_cents', 'shipping_cents', 'total_cents',
            'line_count', 'po_reference', 'quote_number', 'company', 'placed_by', 'created_at', 'updated_at',
        ])
        ->and($response->json('data.0.created_at'))->toBe('2026-11-05T12:00:00Z')
        ->and($response->json('data.0.po_reference'))->toBe('Job 12/B_7')
        ->and($response->json('data.0.line_count'))->toBe(1);
});

it('filters by status, single and CSV', function (string $query, array $expected) {
    expect(numbers($this->getJson("/api/v1/orders?{$query}")->assertOk()))->toEqualCanonicalizing(
        array_map(fn ($n) => $this->{$n}->order_number, $expected)
    );
})->with([
    ['status=pending', ['o1']], ['status=pending,shipped', ['o1', 'o3']], ['status=pending, shipped', ['o1', 'o3']],
    ['status=delivered', []], ['status=cancelled,confirmed,pending,shipped', ['o1', 'o2', 'o3', 'o4']],
]);

it('treats from and to as inclusive UTC days with exact edges', function (string $query, array $expected) {
    expect(numbers($this->getJson("/api/v1/orders?{$query}&sort=created_at")->assertOk()))->toBe(
        array_map(fn ($n) => $this->{$n}->order_number, $expected)
    );
})->with([
    'to includes 23:59:59' => ['to=2026-10-31', ['o4', 'o1']],
    'from includes 00:00:00' => ['from=2026-11-01', ['o2', 'o3']],
    'from only' => ['from=2026-11-02', ['o3']],
    'to only' => ['to=2026-09-15', ['o4']],
    'range' => ['from=2026-10-31&to=2026-11-01', ['o1', 'o2']],
    'same day' => ['from=2026-11-05&to=2026-11-05', ['o3']],
    'combined with status' => ['from=2026-10-01&status=confirmed,shipped', ['o2', 'o3']],
]);

it('searches by order number: full, partial, lowercase, with dashes and spaces', function (string $q, array $expected) {
    expect(numbers($this->getJson('/api/v1/orders?q='.rawurlencode($q))->assertOk()))->toEqualCanonicalizing(
        array_map(fn ($n) => $this->{$n}->order_number, $expected)
    );
})->with([
    'full' => ['ORD-K7Q2M-X9PA3', ['o1']], 'lowercase' => ['ord-k7q2m-x9pa3', ['o1']], 'fragment' => ['k7q2', ['o1']],
    'prefix with dash' => ['ORD-K7Q2', ['o1']], 'spaces' => ['ord k7q2m', ['o1']], 'across the dash' => ['M-X9', ['o1']],
    'second block' => ['VWXYZ', ['o3']], 'digits' => ['2345', ['o4']], 'no match' => ['QQQQQQ', []],
]);

it('searches the PO reference case-insensitively as a substring, with wildcards literal', function (string $q, array $expected) {
    expect(numbers($this->getJson('/api/v1/orders?q='.rawurlencode($q))->assertOk()))->toEqualCanonicalizing(
        array_map(fn ($n) => $this->{$n}->order_number, $expected)
    );
})->with([
    'full' => ['PO-4711', ['o1']], 'lowercase' => ['po-4711', ['o1']], 'substring' => ['471', ['o1']], 'with slash' => ['12/b', ['o3']],
    'with space' => ['job 12', ['o3']], 'literal underscore' => ['b_7', ['o3']], 'underscore is not a wildcard' => ['B_7', ['o3']], 'underscore is not a wildcard 2' => ['J_B', []],
    'wildcard percent' => ['%', []], 'percent not a wildcard' => ['PO%11', []], 'underscore wildcard' => ['_', ['o3']],
    'single char wildcard' => ['PO_4711', []], 'backslash' => ['\\', []], 'double dash is literal' => ['--', []],
]);

it('never matches everything for a search that normalises to nothing', function (string $q) {
    $this->getJson('/api/v1/orders?q='.rawurlencode($q))->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
})->with(['%', '%%%', '\\', '___ %', '"', "'"]);

it('sorts by every whitelisted value with the id tie-break and stable pages', function (string $sort, array $expected) {
    expect(numbers($this->getJson("/api/v1/orders?sort={$sort}")->assertOk()))->toBe(array_map(fn ($n) => $this->{$n}->order_number, $expected));
})->with([
    'created_at' => ['created_at', ['o4', 'o1', 'o2', 'o3']], '-created_at' => ['-created_at', ['o3', 'o2', 'o1', 'o4']],
    'total' => ['total', ['o4', 'o2', 'o3', 'o1']], // o2 and o3 tie on 300: id ascending
    '-total' => ['-total', ['o1', 'o2', 'o3', 'o4']],
    'number' => ['number', ['o4', 'o2', 'o1', 'o3']], '-number' => ['-number', ['o3', 'o1', 'o2', 'o4']],
]);

it('pages without overlap or gaps and reports a page beyond the last as empty', function () {
    $seen = [];
    foreach ([1, 2, 3] as $page) {
        $response = $this->getJson("/api/v1/orders?per_page=2&page={$page}&sort=total")->assertOk();
        $seen = array_merge($seen, numbers($response));
    }
    expect($seen)->toBe(array_map(fn ($n) => $this->{$n}->order_number, ['o4', 'o2', 'o3', 'o1']));

    $this->getJson('/api/v1/orders?per_page=2&page=3')->assertOk()
        ->assertExactJson(['data' => [], 'meta' => ['current_page' => 3, 'last_page' => 2, 'per_page' => 2, 'total' => 4, 'from' => null, 'to' => null]]);
    $this->getJson('/api/v1/orders?status=delivered')->assertOk()
        ->assertJsonPath('meta.last_page', 1)->assertJsonPath('meta.total', 0)->assertJsonPath('meta.from', null);
});

it('accepts per_page 1 to 100', function (int $perPage) {
    $this->getJson("/api/v1/orders?per_page={$perPage}")->assertOk()->assertJsonPath('meta.per_page', $perPage);
})->with([1, 20, 100]);

it('rejects bad parameters with 422 keyed by the parameter name', function (string $query, string $key) {
    $this->getJson("/api/v1/orders?{$query}")->assertStatus(422)->assertJsonValidationErrors($key);
})->with([
    ['status=bogus', 'status'], ['status[]=pending', 'status'], ['status=pending,', 'status'], ['status=,', 'status'], ['status=pending,bogus', 'status'],
    ['status=%FF', 'status'], ['from=2026-02-30', 'from'], ['from=abc', 'from'], ['to=2026-13-01', 'to'], ['from=2026-1-1', 'from'],
    ['from[]=2026-01-01', 'from'], ['from=2026-11-05&to=2026-11-04', 'from'], ['sort=bogus', 'sort'], ['sort=-id', 'sort'], ['sort[]=total', 'sort'],
    ['page=0', 'page'], ['page=-1', 'page'], ['page=abc', 'page'], ['page=10001', 'page'], ['per_page=0', 'per_page'], ['per_page=101', 'per_page'],
    ['per_page=1.5', 'per_page'], ['q='.str_repeat('a', 33), 'q'], ['q[]=x', 'q'], ['q=a%00b', 'q'], ['q=%FF', 'q'],
]);

it('accepts a 32 character q and ignores unknown parameters', function () {
    $this->getJson('/api/v1/orders?q='.str_repeat('a', 32).'&foo=bar')->assertOk();
});

it('treats a lone leading or trailing NUL in q as whitespace, without a 500', function () {
    $this->getJson('/api/v1/orders?q=%00')->assertOk();
    $this->getJson('/api/v1/orders?q=PO-4711%00')->assertOk()->assertJsonPath('meta.total', 1);
});

it('survives hostile q values without a 500', function (string $q) {
    expect($this->getJson("/api/v1/orders?q={$q}")->getStatusCode())->toBeIn([200, 422])->and(Order::count())->toBe(4);
})->with([rawurlencode("' OR 1=1 --"), rawurlencode('x\'; DROP TABLE orders;--'), '%22', '%5C', '%25%5C%25', rawurlencode("\u{1F600}"), '62%0005']);

it('applies the company filter only for staff and combines it with the other filters', function () {
    $other = Order::factory()->forCompany($this->w->b, $this->w->b1)->create(['order_number' => 'ORD-GHJKM-NPQRS', 'po_reference' => 'PO-4711']);

    // Customer: foreign PO match never leaks.
    expect(numbers($this->getJson('/api/v1/orders?q=PO-4711')))->toBe([$this->o1->order_number]);

    loginAs($this->w->staff);
    expect(numbers($this->getJson('/api/v1/orders?q=PO-4711&sort=number')))->toBe([$other->order_number, $this->o1->order_number])
        ->and(numbers($this->getJson("/api/v1/orders?q=PO-4711&company_id={$this->w->b->id}")))->toBe([$other->order_number]);
});

it('treats leading or trailing NUL in status, from and to as trimmed (absent)', function (string $param, string $value) {
    $this->getJson("/api/v1/orders?{$param}={$value}")->assertOk()->assertJsonPath('meta.total', 4);
})->with([['status', '%00'], ['from', '%00'], ['to', '%00'], ['q', '%00']]);
