<?php

use App\Models\Invoice;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->w = orderWorld();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'UTC'));
    // Three invoices with distinct dates, totals and PO references, created in this order (numbers 1, 2, 3).
    $make = fn (string $issue, string $due, int $price, ?string $po) => Invoice::factory()->forOrder(orderWith($this->w->a, [[$price, 1]], 'confirmed', ['po_reference' => $po]))->issuedOn($issue)->dueOn($due)->create();
    $this->i1 = $make('2026-10-01', '2026-12-30', 3000, 'PO-4711/A');
    $this->i2 = $make('2026-10-15', '2026-12-10', 1000, '50%_off\\sale');
    $this->i3 = $make('2026-10-15', '2026-12-20', 2000, null);
    loginAs($this->w->a1);
});

afterEach(fn () => $this->travelBack());

function listNumbers(string $query = ''): array
{
    return test()->getJson('/api/v1/invoices'.($query === '' ? '' : "?{$query}"))->assertOk()->json('data.*.number');
}

it('returns the list shape with exactly the InvoiceResource keys and the six-key meta', function () {
    $response = $this->getJson('/api/v1/invoices')->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'meta'])
        ->and(array_keys($response->json('meta')))->toBe(['current_page', 'last_page', 'per_page', 'total', 'from', 'to'])
        ->and($response->json('meta'))->toBe(['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 3, 'from' => 1, 'to' => 3])
        ->and(array_keys($response->json('data.0')))->toBe(INVOICE_KEYS);
});

it('sorts newest issue date first by default with the internal id as tie-break', function () {
    // i2 and i3 share an issue date: the lower internal id (i2) comes first.
    expect(listNumbers())->toBe([$this->i2->number, $this->i3->number, $this->i1->number]);
});

it('supports all eight sort values', function (string $sort, array $expected) {
    $numbers = ['i1' => $this->i1->number, 'i2' => $this->i2->number, 'i3' => $this->i3->number];

    expect(listNumbers("sort={$sort}"))->toBe(array_map(fn ($k) => $numbers[$k], $expected));
})->with([
    'issue_date' => ['issue_date', ['i1', 'i2', 'i3']],
    '-issue_date' => ['-issue_date', ['i2', 'i3', 'i1']],
    'due_date' => ['due_date', ['i2', 'i3', 'i1']],
    '-due_date' => ['-due_date', ['i1', 'i3', 'i2']],
    'number' => ['number', ['i1', 'i2', 'i3']],
    '-number' => ['-number', ['i3', 'i2', 'i1']],
    'total' => ['total', ['i2', 'i3', 'i1']],
    '-total' => ['-total', ['i1', 'i3', 'i2']],
]);

it('rejects an unknown sort and out-of-range paging with 422 on that parameter', function (string $query, string $field) {
    $this->getJson("/api/v1/invoices?{$query}")->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['sort=status', 'sort'], ['sort=-id', 'sort'], ['per_page=101', 'per_page'], ['per_page=0', 'per_page'], ['page=0', 'page'], ['page=10001', 'page'],
    ['status=bogus', 'status'], ['status=open,,paid', 'status'], ['status[]=open', 'status'], ['from=2026-02-30', 'from'], ['to=2026-10-15T00:00:00Z', 'to'],
    ['from=2026-11-01&to=2026-10-01', 'from'], ['q[]=x', 'q'], ['q=a%00b', 'q'], ['q='.str_repeat('a', 33), 'q'],
]);

it('paginates with exact meta and an empty page past the end', function () {
    $page = $this->getJson('/api/v1/invoices?per_page=2&page=2')->assertOk();
    $beyond = $this->getJson('/api/v1/invoices?per_page=2&page=9')->assertOk();

    expect($page->json('data.*.number'))->toBe([$this->i1->number])
        ->and($page->json('meta'))->toBe(['current_page' => 2, 'last_page' => 2, 'per_page' => 2, 'total' => 3, 'from' => 3, 'to' => 3])
        ->and($beyond->json('data'))->toBe([])->and($beyond->json('meta.from'))->toBeNull()->and($beyond->json('meta.to'))->toBeNull();
});

it('answers no matches with an empty list, total 0 and last_page 1', function () {
    $response = $this->getJson('/api/v1/invoices?q=nothing-matches')->assertOk();

    expect($response->json('data'))->toBe([])->and($response->json('meta'))->toBe(['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0, 'from' => null, 'to' => null]);
});

it('filters the issue date inclusively with from and to', function () {
    expect(listNumbers('from=2026-10-15'))->toBe([$this->i2->number, $this->i3->number])
        ->and(listNumbers('to=2026-10-14'))->toBe([$this->i1->number])
        ->and(listNumbers('from=2026-10-15&to=2026-10-15'))->toBe([$this->i2->number, $this->i3->number])
        ->and(listNumbers('from=2026-10-01&to=2026-10-01'))->toBe([$this->i1->number]);
});

it('searches by invoice number in any dash and case form', function (string $q) {
    expect(listNumbers('q='.urlencode(str_replace('{n}', $this->i2->number, $q))))->toBe([$this->i2->number]);
})->with(['{n}', 'inv-2026-000002', 'INV2026000002', '2026000002', ' inv 2026 000002 ']);

it('searches by order number and treats a number substring as a substring', function () {
    expect(listNumbers('q='.$this->i3->order_number))->toBe([$this->i3->number])
        ->and(listNumbers('q='.strtolower(str_replace('-', '', $this->i3->order_number))))->toBe([$this->i3->number]);
});

it('searches the PO reference case-insensitively with % _ and backslash taken literally', function () {
    expect(listNumbers('q=po-4711'))->toBe([$this->i1->number])
        ->and(listNumbers('q='.urlencode('50%_off')))->toBe([$this->i2->number])
        ->and(listNumbers('q='.urlencode('%')))->toBe([$this->i2->number])
        ->and(listNumbers('q='.urlencode('_')))->toBe([$this->i2->number])
        ->and(listNumbers('q='.urlencode('\\')))->toBe([$this->i2->number])
        ->and(listNumbers('q='.urlencode('off\\sale')))->toBe([$this->i2->number]);
});

it('matches nothing by number when q has no letters or digits, never everything', function () {
    expect(listNumbers('q='.urlencode('---')))->toBe([])->and(listNumbers('q='.urlencode('_-_')))->toBe([]);
});

it('trims leading and trailing NUL characters in q but rejects an interior one', function () {
    expect(listNumbers('q=%00'.$this->i1->number.'%00'))->toBe([$this->i1->number]);
    $this->getJson('/api/v1/invoices?q=INV%002026')->assertUnprocessable()->assertJsonValidationErrors('q');
});

it('ignores unknown query parameters', function () {
    expect(listNumbers('foo=bar&company=1&id=3'))->toHaveCount(3);
});

it('lets one company never see the other when searching by number, even for staff scoped by company_id', function () {
    $theirs = Invoice::factory()->forCompany($this->w->b)->create();

    expect(listNumbers('q='.$theirs->number))->toBe([])
        ->and(collect(loginAs($this->w->staff)->getJson("/api/v1/invoices?company_id={$this->w->b->id}")->json('data'))->pluck('number')->all())->toBe([$theirs->number]);
});
