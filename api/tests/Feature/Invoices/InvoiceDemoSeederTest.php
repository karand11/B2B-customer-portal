<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Support\InvoiceTotals;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InvoiceDemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    // The seeded due date of the last invoice is relative to the seeding day; everything else is fixed.
    $this->travelTo(CarbonImmutable::parse('2026-11-27 12:00:00', 'UTC'));
});

afterEach(fn () => $this->travelBack());

function seededInvoicesOf(string $email): array
{
    return Invoice::visibleTo(User::where('email', $email)->first())->orderBy('number_seq')->pluck('number')->all();
}

it('seeds the five fixed invoices idempotently with gap-free numbers and a counter of 5', function () {
    $this->seed(InvoiceDemoSeeder::class);
    $first = Invoice::orderBy('number_seq')->get()->map->only(['number', 'order_number', 'status', 'issue_date', 'due_date', 'paid_on', 'total_cents'])->all();
    $this->seed(InvoiceDemoSeeder::class);

    expect(Invoice::count())->toBe(5)
        ->and(Invoice::orderBy('number_seq')->get()->map->only(['number', 'order_number', 'status', 'issue_date', 'due_date', 'paid_on', 'total_cents'])->all())->toEqual($first)
        ->and(Invoice::orderBy('number_seq')->pluck('number')->all())->toBe(['INV-2026-000001', 'INV-2026-000002', 'INV-2026-000003', 'INV-2026-000004', 'INV-2026-000005'])
        ->and(Invoice::orderBy('number_seq')->pluck('number_seq')->all())->toBe([1, 2, 3, 4, 5])
        ->and((int) DB::table('invoice_number_counters')->where('year', 2026)->value('last_value'))->toBe(5)
        ->and(Invoice::orderBy('number_seq')->pluck('order_number')->all())->toBe(['ORD-NWAAA-AAAA5', 'ORD-GXAAA-AAAA3', 'ORD-NWAAA-AAAA4', 'ORD-NWAAA-AAAA3', 'ORD-NWAAA-AAAA3'])
        ->and(Invoice::orderBy('number_seq')->get()->map(fn ($i) => $i->status->value)->all())->toBe(['paid', 'overdue', 'open', 'void', 'open'])
        ->and(Invoice::orderBy('number_seq')->get()->map(fn ($i) => $i->issue_date->toDateString())->all())->toBe(['2026-09-16', '2026-10-06', '2026-10-29', '2026-11-13', '2026-11-14']);
});

it('keeps every invariant: totals, tax formula, item sums, history and unique active invoice per order', function () {
    $this->seed(InvoiceDemoSeeder::class);

    foreach (Invoice::with('items')->get() as $invoice) {
        $totals = InvoiceTotals::compute($invoice->subtotal_cents, $invoice->vat_rate_bp);
        expect($invoice->subtotal_cents)->toBe((int) $invoice->items->sum('line_total_cents'))->and($invoice->tax_cents)->toBe($totals['tax'])->and($invoice->total_cents)->toBe($totals['total'])
            ->and($invoice->items->pluck('position')->all())->toBe(range(1, $invoice->items->count()))
            ->and($invoice->buyer['address_lines'])->not->toBeEmpty()->and($invoice->seller['name'])->toBe(config('invoicing.seller.name'));
    }
    expect(DB::table('invoice_status_history')->whereNull('actor_user_id')->pluck('to_status')->all())->toBe(['overdue'])
        ->and(DB::table('invoices')->where('status', '<>', 'void')->select('order_id')->groupBy('order_id')->havingRaw('count(*) > 1')->get()->count())->toBe(0)
        ->and(DB::table('invoice_status_history')->where('note', 'Wrong billing address (demo)')->count())->toBe(1)
        ->and(Invoice::where('order_number', 'ORD-NWAAA-AAAA3')->orderBy('number_seq')->get()->map(fn ($i) => $i->status->value)->all())->toBe(['void', 'open']);
});

it('gives the companies fake billing data', function () {
    $this->seed(InvoiceDemoSeeder::class);

    foreach (['Northwind Machinery Ltd', 'Globex Industrial Supply'] as $name) {
        $company = Company::where('name', $name)->firstOrFail();
        expect($company->billing_address)->not->toBeEmpty()->and($company->tax_id)->not->toBeEmpty();
    }
});

it('shows Alice and Bob four Northwind invoices, Carol one and staff all five', function () {
    $this->seed(InvoiceDemoSeeder::class);
    $northwind = ['INV-2026-000001', 'INV-2026-000003', 'INV-2026-000004', 'INV-2026-000005'];

    expect(seededInvoicesOf('alice@northwind.test'))->toBe($northwind)->and(seededInvoicesOf('bob@northwind.test'))->toBe($northwind)
        ->and(seededInvoicesOf('carol@globex.test'))->toBe(['INV-2026-000002'])->and(seededInvoicesOf('staff@portal.test'))->toHaveCount(5)->and(seededInvoicesOf('admin@portal.test'))->toHaveCount(5);
});

it('shows the documented effective statuses and lets the command materialise INV-2026-000003', function () {
    $this->seed(InvoiceDemoSeeder::class);
    $staff = User::where('email', 'staff@portal.test')->first();
    $status = fn () => collect(loginAs($staff)->getJson('/api/v1/invoices?sort=number')->json('data'))->pluck('status', 'number')->all();

    expect($status())->toBe(['INV-2026-000001' => 'paid', 'INV-2026-000002' => 'overdue', 'INV-2026-000003' => 'overdue', 'INV-2026-000004' => 'void', 'INV-2026-000005' => 'open'])
        ->and(loginAs($staff)->getJson('/api/v1/invoices?status=overdue')->json('meta.total'))->toBe(2)
        ->and(DB::table('invoices')->where('number', 'INV-2026-000003')->value('status'))->toBe('open');

    Artisan::call('invoices:mark-overdue');

    expect(Invoice::where('number', 'INV-2026-000003')->first()->status->value)->toBe('overdue')
        ->and(DB::table('invoice_status_history')->whereNull('actor_user_id')->count())->toBe(2)
        ->and($status())->toBe(['INV-2026-000001' => 'paid', 'INV-2026-000002' => 'overdue', 'INV-2026-000003' => 'overdue', 'INV-2026-000004' => 'void', 'INV-2026-000005' => 'open']);
});

it('renders the seeded invoices as PDFs', function () {
    $this->seed(InvoiceDemoSeeder::class);
    $staff = User::where('email', 'staff@portal.test')->first();

    foreach (Invoice::all() as $invoice) {
        expect(loginAs($staff)->get("/api/v1/invoices/{$invoice->public_id}/pdf")->assertOk()->getContent())->toStartWith('%PDF-');
    }
});

it('aborts outside local and testing and DatabaseSeeder skips it', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(fn () => app(InvoiceDemoSeeder::class)->run())->toThrow(HttpException::class);

    app(DatabaseSeeder::class)->run();
    expect(Invoice::count())->toBe(0);
})->with(['production', 'staging', 'development', 'qa']);

it('runs from DatabaseSeeder in testing', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Invoice::count())->toBe(5);
});
