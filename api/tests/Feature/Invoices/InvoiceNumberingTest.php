<?php

use App\Exceptions\InvoiceYearRolled;
use App\Models\Invoice;
use App\Models\InvoiceStatusHistory;
use App\Services\InvoiceNumberAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Real second connections and TRUNCATE of the counter table: runs serially, after the parallel step (see Admin/ConcurrencyTest.php).
pest()->group('race');

beforeEach(function () {
    $this->w = orderWorld();
    $this->w->a->forceFill(['billing_address' => "1 Test Road\nTesttown"])->save();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
    InvoiceStatusHistory::flushEventListeners();
});

function counterValue(int $year): ?int
{
    $value = DB::table('invoice_number_counters')->where('year', $year)->value('last_value');

    return $value === null ? null : (int) $value;
}

it('issues sequential numbers INV-YYYY-000001 to 000005, unique per (year, seq)', function () {
    $numbers = [];
    foreach (range(1, 5) as $_) {
        $numbers[] = issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertCreated()->json('data.number');
    }

    expect($numbers)->toBe(['INV-2026-000001', 'INV-2026-000002', 'INV-2026-000003', 'INV-2026-000004', 'INV-2026-000005'])
        ->and(counterValue(2026))->toBe(5)
        ->and(Invoice::count())->toBe(5)
        ->and(Invoice::pluck('number_seq')->sort()->values()->all())->toBe([1, 2, 3, 4, 5]);
});

it('rejects a duplicate number or a duplicate (year, seq) at the database', function () {
    $invoice = makeInvoice($this->w->a);
    $copy = fn (array $overrides = []) => invoiceRow($invoice, ['public_id' => (string) Str::uuid(), 'order_id' => orderWith($this->w->a, [[100, 1]])->id] + $overrides);

    violates(fn () => DB::table('invoices')->insert($copy()), 'invoices_number_');
    violates(fn () => DB::table('invoices')->insert($copy(['number_seq' => 7])), 'invoices_number_parts');
});

it('refuses to allocate outside a transaction (the lock would be released at once)', function () {
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    $connection = DB::connection('pgsql_b');

    try {
        expect($connection->transactionLevel())->toBe(0)
            ->and(fn () => (new InvoiceNumberAllocator($connection))->allocate())->toThrow(LogicException::class);
    } finally {
        DB::purge('pgsql_b');
    }
});

it('locks the counter row across connections and reuses the number after a rollback (no gap)', function () {
    foreach (['pgsql_b', 'pgsql_c'] as $name) {
        config(["database.connections.{$name}" => config('database.connections.pgsql')]);
    }
    [$b, $c] = [DB::connection('pgsql_b'), DB::connection('pgsql_c')];
    $date = CarbonImmutable::parse('2026-11-26', 'UTC');

    try {
        $b->beginTransaction();
        $first = (new InvoiceNumberAllocator($b))->allocate($date);

        // C must wait for B's uncommitted row lock: with a short lock_timeout it fails with SQLSTATE 55P03.
        $c->beginTransaction();
        $c->statement("SET LOCAL lock_timeout = '300ms'");
        $blocked = null;
        try {
            (new InvoiceNumberAllocator($c))->allocate($date);
        } catch (QueryException $e) {
            $blocked = $e->getCode();
        }
        $c->rollBack();
        $b->rollBack();

        $c->beginTransaction();
        $second = (new InvoiceNumberAllocator($c))->allocate($date);
        $c->commit();
        $b->beginTransaction();
        $third = (new InvoiceNumberAllocator($b))->allocate($date);
        $b->commit();

        expect($first->seq)->toBe(1)->and($blocked)->toBe('55P03')
            ->and($second->seq)->toBe(1)->and($second->number)->toBe('INV-2026-000001')
            ->and($third->seq)->toBe(2);
    } finally {
        foreach ([$b, $c] as $connection) {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $b->statement('TRUNCATE invoice_number_counters');
        DB::purge('pgsql_b');
        DB::purge('pgsql_c');
    }
});

it('leaves no gap when the transaction fails after the number was allocated', function () {
    $first = orderWith($this->w->a, [[1000, 1]]);
    $second = orderWith($this->w->a, [[1000, 1]]);
    InvoiceStatusHistory::creating(fn () => throw new RuntimeException('boom after allocation'));

    issueInvoiceAs($this->w->staff, $first)->assertStatus(500);

    expect(Invoice::count())->toBe(0)->and(DB::table('invoice_items')->count())->toBe(0)->and(DB::table('invoice_status_history')->count())->toBe(0)
        ->and(counterValue(2026))->toBeNull();

    InvoiceStatusHistory::flushEventListeners();

    // The next successful issue gets the number the failed attempt would have had.
    issueInvoiceAs($this->w->staff, $second)->assertCreated()->assertJsonPath('data.number', 'INV-2026-000001');
    expect(counterValue(2026))->toBe(1);
});

it('numbers by the UTC year of the issue date and resets on 1 January', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-31 23:59:59', 'UTC'));
    $last = issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertCreated();

    $this->travelTo(CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC'));
    $next = issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertCreated();

    expect($last->json('data.number'))->toBe('INV-2026-000001')->and($last->json('data.issue_date'))->toBe('2026-12-31')
        ->and($next->json('data.number'))->toBe('INV-2027-000001')->and($next->json('data.issue_date'))->toBe('2027-01-01')
        ->and(counterValue(2026))->toBe(1)->and(counterValue(2027))->toBe(1);
});

it('takes the issue date and number year from UTC, not from the application time zone', function () {
    config(['app.timezone' => 'America/Los_Angeles']);
    // 2027-01-01 03:00 UTC is still 31 December in Los Angeles.
    $this->travelTo(CarbonImmutable::parse('2027-01-01 03:00:00', 'UTC'));

    issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertCreated()
        ->assertJsonPath('data.number', 'INV-2027-000001')->assertJsonPath('data.issue_date', '2027-01-01');
});

it('retries once when the clock crosses midnight on 31 December between the two reads, with nothing consumed', function () {
    $calls = 0;
    $clock = function () use (&$calls) {
        return ++$calls <= 1 ? CarbonImmutable::parse('2026-12-31 23:59:59.900', 'UTC') : CarbonImmutable::parse('2027-01-01 00:00:00.100', 'UTC');
    };
    app()->instance(InvoiceNumberAllocator::class, new InvoiceNumberAllocator(null, $clock));
    $this->travelTo(CarbonImmutable::parse('2026-12-31 23:59:59', 'UTC'));

    $response = issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertCreated();

    expect($response->json('data.number'))->toBe('INV-2027-000001')->and($response->json('data.issue_date'))->toBe('2027-01-01')
        ->and(counterValue(2026))->toBeNull()->and(counterValue(2027))->toBe(1);
});

it('throws InvoiceYearRolled when the clock crosses a year boundary between the reads', function () {
    $calls = 0;
    $clock = function () use (&$calls) {
        return ++$calls <= 1 ? CarbonImmutable::parse('2026-12-31 23:59:59', 'UTC') : CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC');
    };

    DB::transaction(fn () => expect(fn () => (new InvoiceNumberAllocator(null, $clock))->allocate())->toThrow(InvoiceYearRolled::class));
});

it('pads to six digits and widens to seven after 999,999, sorting by (year, seq) not by the string', function () {
    DB::table('invoice_number_counters')->insert(['year' => 2026, 'last_value' => 999998, 'updated_at' => now()]);

    $a = issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertCreated();
    $b = issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertCreated();
    $c = issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertCreated();

    expect($a->json('data.number'))->toBe('INV-2026-999999')->and($a->json('data.number'))->toMatch('/^INV-\d{4}-\d{6}$/')
        ->and($b->json('data.number'))->toBe('INV-2026-1000000')->and($c->json('data.number'))->toBe('INV-2026-1000001');

    $order = $this->actingAs($this->w->staff)->getJson('/api/v1/invoices?sort=number')->assertOk()->json('data.*.number');
    expect($order)->toBe(['INV-2026-999999', 'INV-2026-1000000', 'INV-2026-1000001']);
});
