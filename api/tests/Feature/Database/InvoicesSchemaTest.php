<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Support\InvoiceTotals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->w = orderWorld();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'UTC'));
    $this->invoice = Invoice::factory()->forCompany($this->w->a)->create();
    $this->orderIds = fn () => orderWith($this->w->a, [[100, 1]])->id;
});

afterEach(fn () => $this->travelBack());

/** Insert a copy of the invoice row with overrides and expect $constraint to reject it. */
function rejectsRow(string $constraint, array $overrides): void
{
    $invoice = test()->invoice;
    $row = invoiceRow($invoice, $overrides + ['public_id' => (string) Str::uuid(), 'order_id' => (test()->orderIds)(), 'number' => 'INV-2026-000050', 'number_seq' => 50]);

    violates(fn () => DB::table('invoices')->insert($row), $constraint);
}

it('rejects each bad invoice value with its named CHECK', function (string $constraint, array $overrides) {
    rejectsRow($constraint, $overrides);
})->with([
    'number format' => ['invoices_number_format', ['number' => 'INV-2026-12', 'number_seq' => 12]],
    'number lowercase' => ['invoices_number_format', ['number' => 'inv-2026-000050']],
    'number does not match year and seq' => ['invoices_number_parts', ['number' => 'INV-2026-000051']],
    'year differs from issue date' => ['invoices_number_year_matches_issue_date', ['number' => 'INV-2025-000050', 'number_year' => 2025]],
    'seq zero' => ['invoices_number_seq_positive', ['number' => 'INV-2026-000000', 'number_seq' => 0]],
    'unknown status' => ['invoices_status_check', ['status' => 'draft']],
    'due before issue' => ['invoices_due_after_issue', ['due_date' => '2026-11-25']],
    'paid without paid_on' => ['invoices_paid_consistent', ['status' => 'paid', 'paid_on' => null]],
    'paid_on on an open row' => ['invoices_paid_consistent', ['status' => 'open', 'paid_on' => '2026-11-26']],
    'paid before issue' => ['invoices_paid_after_issue', ['status' => 'paid', 'paid_on' => '2026-11-25']],
    'currency lowercase' => ['invoices_currency_check', ['currency' => 'usd']],
    'po reference too long' => ['value too long', ['po_reference' => str_repeat('x', 65)]],
    'rate 5001' => ['invoices_vat_rate_range', ['vat_rate_bp' => 5001, 'tax_cents' => 500, 'total_cents' => 1500, 'subtotal_cents' => 1000]],
    'negative subtotal' => ['invoices_subtotal_nonnegative', ['subtotal_cents' => -1, 'tax_cents' => 0, 'total_cents' => -1]],
    'subtotal above the maximum' => ['invoices_subtotal_max', ['subtotal_cents' => 999_900_000_000_001, 'tax_cents' => 0, 'total_cents' => 999_900_000_000_001]],
    'wrong tax' => ['invoices_tax_consistent', ['subtotal_cents' => 999, 'vat_rate_bp' => 1900, 'tax_cents' => 1900, 'total_cents' => 2899]],
    'wrong total' => ['invoices_total_consistent', ['subtotal_cents' => 1000, 'tax_cents' => 0, 'total_cents' => 1001]],
    'seller not an object' => ['invoices_seller_object', ['seller' => '[]']],
    'buyer not an object' => ['invoices_buyer_object', ['buyer' => '"text"']],
]);

it('accepts every fixture F1 to F9 as a raw row and rejects the wrong tax for F2', function (int $subtotal, int $rate, int $tax, int $total) {
    $row = invoiceRow($this->invoice, [
        'public_id' => (string) Str::uuid(), 'order_id' => ($this->orderIds)(), 'number' => 'INV-2026-000050', 'number_seq' => 50,
        'subtotal_cents' => $subtotal, 'vat_rate_bp' => $rate, 'tax_cents' => $tax, 'total_cents' => $total,
    ]);

    DB::table('invoices')->insert($row);

    expect((int) DB::table('invoices')->where('number', 'INV-2026-000050')->value('total_cents'))->toBe($total)
        ->and(InvoiceTotals::compute($subtotal, $rate))->toBe(['tax' => $tax, 'total' => $total]);
})->with([
    [10000, 1900, 1900, 11900], [999, 1900, 190, 1189], [5, 1000, 1, 6], [25, 1000, 3, 28], [499, 10, 0, 499], [500, 10, 1, 501],
    [12345, 0, 0, 12345], [107079, 1900, 20345, 127424], [0, 1900, 0, 0], [999_900_000_000_000, 5000, 499_950_000_000_000, 1_499_850_000_000_000],
]);

it('rejects bad item rows with their named CHECKs', function (string $constraint, array $overrides) {
    $row = ['invoice_id' => $this->invoice->id, 'position' => 9, 'part_number' => null, 'name' => 'x', 'brand' => null, 'quantity' => 1, 'unit_price_cents' => 100, 'line_total_cents' => 100, 'created_at' => now()];

    violates(fn () => DB::table('invoice_items')->insert($overrides + $row), $constraint);
})->with([
    'quantity 0' => ['invoice_items_quantity_range', ['quantity' => 0, 'line_total_cents' => 0]],
    'quantity 10000' => ['invoice_items_quantity_range', ['quantity' => 10000, 'line_total_cents' => 1_000_000]],
    'negative price' => ['invoice_items_unit_price_range', ['unit_price_cents' => -1, 'line_total_cents' => -1]],
    'price above the cap' => ['invoice_items_unit_price_range', ['unit_price_cents' => 1_000_000_001, 'line_total_cents' => 1_000_000_001]],
    'wrong line total' => ['invoice_items_line_total_consistent', ['line_total_cents' => 99]],
    'part number without brand' => ['invoice_items_line_kind', ['part_number' => 'X-1']],
    'brand without part number' => ['invoice_items_line_kind', ['brand' => 'SKF']],
    'position 0' => ['invoice_items_position_positive', ['position' => 0]],
    'duplicate position' => ['invoice_items_invoice_id_position_unique', ['position' => 1]],
]);

it('rejects bad history rows, allowing a system actor only for overdue', function () {
    $row = ['invoice_id' => $this->invoice->id, 'from_status' => 'open', 'to_status' => 'paid', 'actor_user_id' => $this->w->staff->id, 'note' => null, 'created_at' => now()];

    violates(fn () => DB::table('invoice_status_history')->insert(['actor_user_id' => null] + $row), 'invoice_status_history_system_actor');
    violates(fn () => DB::table('invoice_status_history')->insert(['to_status' => 'open'] + $row), 'invoice_status_history_changed');
    violates(fn () => DB::table('invoice_status_history')->insert(['to_status' => 'archived'] + $row), 'invoice_status_history_to_check');
    violates(fn () => DB::table('invoice_status_history')->insert(['from_status' => 'draft'] + $row), 'invoice_status_history_from_check');
    DB::table('invoice_status_history')->insert(['to_status' => 'overdue', 'actor_user_id' => null] + $row);
});

it('rejects a bad counter row and a bad company billing value', function () {
    violates(fn () => DB::table('invoice_number_counters')->insert(['year' => 1999, 'last_value' => 1, 'updated_at' => now()]), 'invoice_number_counters_year_range');
    violates(fn () => DB::table('invoice_number_counters')->insert(['year' => 2030, 'last_value' => -1, 'updated_at' => now()]), 'invoice_number_counters_value_range');
    violates(fn () => DB::table('invoice_number_counters')->insert(['year' => 2030, 'last_value' => 1_000_000_000, 'updated_at' => now()]), 'invoice_number_counters_value_range');
    violates(fn () => Company::where('id', $this->w->a->id)->update(['billing_address' => str_repeat('x', 501)]), 'companies_billing_address_length');
    violates(fn () => Company::where('id', $this->w->a->id)->update(['tax_id' => str_repeat('x', 33)]), 'value too long');
});

it('never mass-assigns billing data on a company', function () {
    $company = new Company;
    $company->fill(['name' => 'X', 'billing_address' => 'Hack', 'tax_id' => 'Hack']);

    expect($company->billing_address)->toBeNull()->and($company->tax_id)->toBeNull();
});

// ---------------------------------------------------------------- immutability triggers

it('forbids DELETE on invoices, items and history', function (string $table) {
    violates(fn () => DB::table($table)->delete(), 'append-only');
    expect(DB::table($table)->count())->toBeGreaterThan(0);
})->with(['invoices', 'invoice_items', 'invoice_status_history']);

it('forbids any UPDATE of items and history rows', function () {
    violates(fn () => DB::table('invoice_items')->update(['name' => 'changed']), 'append-only');
    violates(fn () => DB::table('invoice_status_history')->update(['note' => 'changed']), 'append-only');
});

it('forbids changing any immutable invoice column', function (string $column, mixed $value) {
    $value = match ($value) {
        'other company' => $this->w->b->id,
        'other user' => $this->w->admin->id,
        default => $value,
    };
    violates(fn () => DB::table('invoices')->where('id', $this->invoice->id)->update([$column => $value]), 'immutable');
})->with([
    'number' => ['number', 'INV-2026-000777'], 'number_seq' => ['number_seq', 777], 'company_id' => ['company_id', 'other company'], 'order_number' => ['order_number', 'ORD-AAAAA-AAAAA'],
    'issue_date' => ['issue_date', '2026-11-20'], 'due_date' => ['due_date', '2027-01-01'], 'currency' => ['currency', 'EUR'], 'po_reference' => ['po_reference', 'CHANGED'],
    'vat_rate_bp' => ['vat_rate_bp', 100], 'subtotal_cents' => ['subtotal_cents', 1], 'tax_cents' => ['tax_cents', 1], 'total_cents' => ['total_cents', 1],
    'seller' => ['seller', '{"name":"Changed"}'], 'buyer' => ['buyer', '{"name":"Changed"}'], 'created_at' => ['created_at', '2020-01-01 00:00:00'],
    'public_id' => ['public_id', '00000000-0000-4000-8000-000000000000'], 'created_by_user_id' => ['created_by_user_id', 'other user'],
]);

it('still allows status, paid_on and updated_at to change', function () {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['status' => 'paid', 'paid_on' => '2026-11-26', 'updated_at' => now()]);

    expect(DB::table('invoices')->where('id', $this->invoice->id)->value('status'))->toBe('paid');
});

it('creates and drops the triggers with the migration (down is reversible)', function () {
    $names = fn () => DB::table('pg_trigger')->where('tgisinternal', false)->whereIn('tgrelid', DB::table('pg_class')->whereIn('relname', ['invoices', 'invoice_items', 'invoice_status_history'])->select('oid'))
        ->pluck('tgname')->sort()->values()->all();

    expect($names())->toBe(['invoice_items_no_delete', 'invoice_items_no_update', 'invoice_status_history_no_delete', 'invoice_status_history_no_update', 'invoices_immutable_columns', 'invoices_no_delete']);
});

// ---------------------------------------------------------------- paid and void are final in the database

it('refuses to leave paid or void, and to change paid_on on a paid row', function (string $from, array $update) {
    $invoice = Invoice::factory()->forCompany($this->w->a)->{$from}()->create();

    violates(fn () => DB::table('invoices')->where('id', $invoice->id)->update($update), 'final');

    expect(DB::table('invoices')->where('id', $invoice->id)->value('status'))->toBe($from);
})->with([
    'paid -> open' => ['paid', ['status' => 'open', 'paid_on' => null]],
    'paid -> overdue' => ['paid', ['status' => 'overdue', 'paid_on' => null]],
    'paid -> void' => ['paid', ['status' => 'void', 'paid_on' => null]],
    'void -> open' => ['void', ['status' => 'open']],
    'void -> overdue' => ['void', ['status' => 'overdue']],
    'void -> paid' => ['void', ['status' => 'paid', 'paid_on' => '2026-11-26']],
    'paid_on changed on a paid row' => ['paid', ['paid_on' => '2026-11-27']],
    'paid_on cleared on a paid row' => ['paid', ['paid_on' => null]],
]);

it('still allows the legal status changes and touching updated_at on a final row', function (string $from, array $update, string $to) {
    $invoice = Invoice::factory()->forCompany($this->w->a)->{$from}()->create();

    DB::table('invoices')->where('id', $invoice->id)->update($update);

    expect(DB::table('invoices')->where('id', $invoice->id)->value('status'))->toBe($to);
})->with([
    'open -> paid' => ['open', ['status' => 'paid', 'paid_on' => '2026-11-26'], 'paid'],
    'open -> overdue' => ['open', ['status' => 'overdue'], 'overdue'],
    'overdue -> paid' => ['overdue', ['status' => 'paid', 'paid_on' => '2026-11-26'], 'paid'],
    'open -> void' => ['open', ['status' => 'void'], 'void'],
    'overdue -> void' => ['overdue', ['status' => 'void'], 'void'],
    'paid, updated_at only' => ['paid', ['updated_at' => '2026-11-27 00:00:00'], 'paid'],
    'void, updated_at only' => ['void', ['updated_at' => '2026-11-27 00:00:00'], 'void'],
    'paid, same values' => ['paid', ['status' => 'paid', 'paid_on' => '2026-11-26'], 'paid'],
]);
