<?php

use App\Models\User;
use App\Services\AuditLog;
use App\Support\PoReference;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const SEC_LOAD_PASSWORD = 'Load-Test-Passw0rd-1';

beforeEach(fn () => config(['portal.loadtest_password' => SEC_LOAD_PASSWORD]));

/** Run the seeder at a tiny scale. */
function secLoadSeed(array $options = []): int
{
    return Artisan::call('portal:loadtest-seed', $options + ['--products' => 50, '--companies' => 3, '--users' => 6, '--orders' => 40, '--quotes' => 30, '--invoices' => 25, '--audit' => 600]);
}

it('creates the load users, the staff user, the companies, the products and the orders at the requested scale', function () {
    expect(secLoadSeed())->toBe(0);

    expect(DB::table('companies')->count())->toBe(3)
        ->and(User::where('email', 'like', 'load0%@load.test')->count())->toBe(6)
        ->and(User::where('email', 'loadstaff@load.test')->first()->hasRole('staff'))->toBeTrue()
        ->and(User::where('email', 'loadadmin@load.test')->first()->hasRole('admin'))->toBeTrue()
        ->and(User::where('email', 'load001@load.test')->first()->hasRole('customer'))->toBeTrue()
        ->and(DB::table('products')->count())->toBe(50)->and(DB::table('orders')->count())->toBe(40)
        ->and(DB::table('order_items')->count())->toBe(120)
        ->and(DB::table('quote_requests')->count())->toBe(30)->and(DB::table('invoices')->count())->toBe(25)->and(DB::table('audit_log')->count())->toBe(600)
        ->and(Artisan::output())->toContain('3 companies')->toContain('6 customers')->toContain('50 products')->toContain('40 orders')->toContain('30 quotes')->toContain('25 invoices')->toContain('600 audit rows');
});

it('spreads the customers over the companies and gives them the password from the environment, never a demo password', function () {
    secLoadSeed();

    $users = User::where('email', 'like', 'load0%@load.test')->orderBy('email')->get();
    expect($users->pluck('company_id')->unique()->count())->toBe(3)->and(Hash::check(SEC_LOAD_PASSWORD, $users[0]->password))->toBeTrue()
        ->and(Hash::check(TEST_PASSWORD, $users[0]->password))->toBeFalse()->and($users[0]->canSignIn())->toBeTrue();

    $this->postJson('/api/v1/auth/login', ['email' => 'load001@load.test', 'password' => SEC_LOAD_PASSWORD])->assertOk();
});

it('produces realistic, constraint-satisfying products: unique part numbers, mixed stock, about 3 percent price on request and 2 percent inactive', function () {
    secLoadSeed(['--products' => 1300, '--orders' => 0]); // past size code 99, where a naive padding once collided

    $p = DB::table('products');
    expect($p->count())->toBe(1300)->and(DB::table('products')->distinct()->count('part_number_search'))->toBe(1300)
        ->and(DB::table('products')->where('price_on_request', true)->count())->toBe(39)->and(DB::table('products')->where('price_on_request', true)->whereNull('price_cents')->count())->toBe(39)
        ->and(DB::table('products')->where('active', false)->count())->toBe(26)
        ->and(DB::table('products')->distinct()->count('stock_status'))->toBe(4)->and(DB::table('products')->distinct()->count('brand'))->toBe(40)
        ->and(DB::table('products')->whereRaw("part_number ~ '^[0-9A-Z]{2}[0-9]+(-[0-9A-Z]+)?$'")->count())->toBe(1300)
        ->and(DB::table('products')->where('part_number', 'like', '6205%')->count())->toBeGreaterThan(0);
});

it('creates orders of the user\'s own company with lines that add up, a history row each, and valid numbers', function () {
    secLoadSeed();

    $mismatch = DB::selectOne('select count(*) n from orders o where o.subtotal_cents <> (select sum(line_total_cents) from order_items i where i.order_id = o.id) or o.total_cents <> o.subtotal_cents');
    $foreign = DB::selectOne('select count(*) n from orders o join users u on u.id = o.created_by_user_id where u.company_id <> o.company_id');
    $noHistory = DB::selectOne("select count(*) n from orders o where not exists (select 1 from order_status_history h where h.order_id = o.id and h.from_status is null and h.to_status = 'pending')");
    $unpriced = DB::selectOne('select count(*) n from order_items i join products p on p.id = i.product_id where p.price_cents is null');

    expect($mismatch->n)->toBe(0)->and($foreign->n)->toBe(0)->and($noHistory->n)->toBe(0)->and($unpriced->n)->toBe(0)
        ->and(DB::table('orders')->whereRaw("order_number !~ '^ORD-[A-HJKMNP-TV-Z2-9]{5}-[A-HJKMNP-TV-Z2-9]{5}$'")->count())->toBe(0)
        ->and(DB::table('orders')->distinct()->count('status'))->toBeGreaterThan(3);
});

it('gives about 70 percent of the orders a PO reference that follows the PO rule, with some variety, and the invoices copy it', function () {
    secLoadSeed(['--orders' => 200, '--invoices' => 50]);

    $values = DB::table('orders')->whereNotNull('po_reference')->pluck('po_reference')->all();
    $share = count($values) / DB::table('orders')->count();
    $first = DB::table('orders')->whereNotNull('po_reference')->orderBy('id')->pluck('po_reference')->all();
    secLoadSeed(['--orders' => 200, '--invoices' => 50]);

    expect($share)->toBeGreaterThanOrEqual(0.6)->toBeLessThanOrEqual(0.8)
        ->and(array_filter($values, fn (string $v) => PoReference::error($v) !== null))->toBe([])
        ->and(max(array_map('strlen', $values)))->toBeGreaterThanOrEqual(20)->toBeLessThanOrEqual(40)
        ->and(count(array_filter($values, fn (string $v) => str_contains($v, ' '))))->toBeGreaterThan(0)
        ->and(count(array_filter($values, fn (string $v) => str_contains($v, '/'))))->toBeGreaterThan(0)
        ->and(DB::table('orders')->whereNotNull('po_reference')->orderBy('id')->pluck('po_reference')->all())->toBe($first) // deterministic
        ->and(DB::table('invoices')->whereNotNull('po_reference')->count())->toBeGreaterThan(0);
});

it('stays set-based at a medium scale: a join per generated line, never a nested loop over the products', function () {
    $start = microtime(true);
    expect(secLoadSeed(['--products' => 2000, '--orders' => 20000, '--quotes' => 4000, '--invoices' => 8000, '--audit' => 20000]))->toBe(0);
    $seconds = microtime(true) - $start;

    // The nested loop took minutes here; the joined version takes a few seconds. The bound is generous for slow CI disks.
    expect($seconds)->toBeLessThan(90)->and(DB::table('order_items')->count())->toBe(60000)
        ->and(DB::table('quote_items')->count())->toBe(8000 + 2000);
});

it('is idempotent: a second run replaces the data instead of adding to it', function () {
    secLoadSeed();
    secLoadSeed();

    expect(DB::table('products')->count())->toBe(50)->and(DB::table('orders')->count())->toBe(40)->and(DB::table('companies')->count())->toBe(3)
        ->and(User::where('email', 'like', 'load%@load.test')->count())->toBe(8);
});

it('scales products and orders with --scale', function () {
    secLoadSeed(['--scale' => 2, '--orders' => 10]);

    expect(DB::table('products')->count())->toBe(100)->and(DB::table('orders')->count())->toBe(20)
        ->and(DB::table('quote_requests')->count())->toBe(60)->and(DB::table('audit_log')->count())->toBe(1200);
});

it('can skip the orders', function () {
    secLoadSeed(['--orders' => 0]);

    expect(DB::table('orders')->count())->toBe(0)->and(DB::table('products')->count())->toBe(50);
});

it('can NEVER run in production or staging: it refuses and touches nothing', function (string $env) {
    $keep = makeUser('customer');
    $before = [User::count(), DB::table('companies')->count()];
    app()->detectEnvironment(fn () => $env);

    expect(secLoadSeed())->toBe(1);

    expect(Artisan::output())->toContain('Refusing to run')->and([User::count(), DB::table('companies')->count()])->toBe($before)
        ->and(User::whereKey($keep->id)->exists())->toBeTrue()->and(DB::table('products')->count())->toBe(0)
        ->and(DB::table('quote_requests')->count())->toBe(0)->and(DB::table('invoices')->count())->toBe(0)->and(DB::table('audit_log')->count())->toBe(0);
})->with(['production', 'staging', 'prod', 'preview']);

it('runs in local, testing and loadtest', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(secLoadSeed())->toBe(0);
})->with(['local', 'testing', 'loadtest']);

it('refuses without a strong password from the environment, and never falls back to a default', function (?string $password) {
    config(['portal.loadtest_password' => $password]);
    $keep = makeUser('customer');

    expect(secLoadSeed())->toBe(1)->and(User::whereKey($keep->id)->exists())->toBeTrue();
    expect(Artisan::output())->toContain('LOADTEST_PASSWORD');
})->with([[null], [''], ['short-1']]);

it('creates quotes with mixed statuses, lines, a history chain per status, totals that add up, valid dates, and NO attachments', function () {
    secLoadSeed();

    $q = DB::table('quote_requests');
    $unsummed = DB::selectOne("select count(*) n from quote_requests q where q.status in ('quoted','accepted','expired')
        and q.subtotal_cents is distinct from (select sum(line_total_cents) from quote_items i where i.quote_request_id = q.id)");
    $noHistory = DB::selectOne('select count(*) n from quote_requests q where not exists (select 1 from quote_status_history h where h.quote_request_id = q.id and h.from_status is null)');
    $badChain = DB::selectOne('select count(*) n from quote_requests q where (select to_status from quote_status_history h where h.quote_request_id = q.id order by h.id desc limit 1) <> q.status');

    expect($q->distinct()->count('status'))->toBe(6)
        ->and(DB::table('quote_requests')->where('status', 'quoted')->whereRaw('valid_until >= current_date')->count())->toBeGreaterThan(0)
        ->and(DB::table('quote_requests')->where('status', 'quoted')->whereRaw('valid_until < current_date')->count())->toBeGreaterThan(0)
        ->and(DB::table('quote_requests')->whereIn('status', ['submitted', 'in_review', 'declined'])->whereNotNull('total_cents')->count())->toBe(0)
        ->and(DB::table('quote_items')->count())->toBeGreaterThan(60)->and($unsummed->n)->toBe(0)->and($noHistory->n)->toBe(0)->and($badChain->n)->toBe(0)
        ->and(DB::table('quote_requests')->whereRaw("quote_number !~ '^QUO-[A-HJKMNP-TV-Z2-9]{5}-[A-HJKMNP-TV-Z2-9]{5}$'")->count())->toBe(0)
        // a row without its file would break quotes:storage-check and the download
        ->and(DB::table('quote_attachments')->count())->toBe(0);
    expect(Artisan::call('quotes:storage-check', ['--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true)['rows_without_file'])->toBe([]);
});

it('creates invoices from invoiceable orders only, numbered without gaps per year with the counters aligned, in every status, with items and history', function () {
    secLoadSeed();

    $years = DB::table('invoices')->selectRaw('number_year, count(*) n, min(number_seq) lo, max(number_seq) hi')->groupBy('number_year')->get();
    $counters = DB::table('invoice_number_counters')->pluck('last_value', 'year')->all();
    $badItems = DB::selectOne('select count(*) n from invoices i where i.subtotal_cents <> (select sum(line_total_cents) from invoice_items x where x.invoice_id = i.id)');
    $badOrder = DB::selectOne("select count(*) n from invoices i join orders o on o.id = i.order_id where o.status not in ('confirmed','shipped','delivered') or o.subtotal_cents <> i.subtotal_cents or o.company_id <> i.company_id");
    $lastStatus = DB::selectOne('select count(*) n from invoices i where (select to_status from invoice_status_history h where h.invoice_id = i.id order by h.id desc limit 1) <> i.status');

    expect(DB::table('invoices')->count())->toBe(25)->and($years->count())->toBeGreaterThan(0);
    foreach ($years as $y) {
        expect([$y->lo, $y->hi, $y->n])->toBe([1, $y->n, $y->n])->and($counters[$y->number_year])->toBe($y->n);
    }
    expect($counters)->toHaveCount($years->count())
        ->and(DB::table('invoices')->distinct()->count('status'))->toBe(4)
        // effective overdue: stored open and past due, next to open ones still in term
        ->and(DB::table('invoices')->where('status', 'open')->whereRaw('due_date < current_date')->count())->toBeGreaterThan(0)
        ->and(DB::table('invoices')->where('status', 'open')->whereRaw('due_date >= current_date')->count())->toBeGreaterThan(0)
        ->and(DB::table('invoices')->where('status', 'overdue')->whereRaw('due_date >= current_date')->count())->toBe(0)
        ->and($badItems->n)->toBe(0)->and($badOrder->n)->toBe(0)->and($lastStatus->n)->toBe(0)
        ->and(DB::table('invoice_status_history')->count())->toBeGreaterThan(25);

    // the immutability triggers hold for the generated rows like for any other
    $id = DB::table('invoices')->value('id');
    violates(fn () => DB::table('invoices')->where('id', $id)->update(['total_cents' => 1]), 'immutable');
    violates(fn () => DB::table('invoices')->where('id', $id)->delete(), 'append-only');
});

it('lets the real invoice, quote and order endpoints serve the generated data', function () {
    secLoadSeed();
    $staff = User::where('email', 'loadstaff@load.test')->first();
    $this->actingAs($staff);

    expect($this->getJson('/api/v1/invoices?per_page=100')->assertOk()->json('meta.total'))->toBe(25);
    expect($this->getJson('/api/v1/invoices?status=open,overdue&sort=due_date&per_page=100')->assertOk()->json('meta.total'))->toBeGreaterThan(0);
    expect($this->getJson('/api/v1/quotes?per_page=100')->assertOk()->json('meta.total'))->toBe(30);
    expect($this->getJson('/api/v1/orders?per_page=100')->assertOk()->json('meta.total'))->toBe(40);
    $this->getJson('/api/v1/invoices/'.DB::table('invoices')->value('public_id'))->assertOk();
    $this->getJson('/api/v1/quotes/'.DB::table('quote_requests')->value('quote_number'))->assertOk();
});

it('creates audit rows over twelve months with valid actions, subject types, actors and real subjects', function () {
    secLoadSeed();

    $actions = DB::table('audit_log')->distinct()->pluck('action')->all();
    $span = DB::selectOne('select extract(day from max(occurred_at) - min(occurred_at)) days from audit_log');
    $badActor = DB::selectOne("select count(*) n from audit_log a left join users u on u.id = a.actor_user_id where u.id is null or a.actor_role <> case when u.email = 'loadadmin@load.test' then 'admin' when u.email = 'loadstaff@load.test' then 'staff' else 'customer' end");
    $orphanOrder = DB::selectOne("select count(*) n from audit_log a where a.subject_type = 'order' and not exists (select 1 from orders o where o.order_number = a.subject_id)");
    $orphanInvoice = DB::selectOne("select count(*) n from audit_log a where a.subject_type = 'invoice' and not exists (select 1 from invoices i where i.number = a.subject_id)");

    expect(array_diff($actions, AuditLog::ACTIONS))->toBe([])->and(count($actions))->toBeGreaterThan(8)
        ->and(DB::table('audit_log')->distinct()->count('subject_type'))->toBeGreaterThan(4)
        ->and((int) $span->days)->toBeGreaterThan(300)->and($badActor->n)->toBe(0)->and($orphanOrder->n)->toBe(0)->and($orphanInvoice->n)->toBe(0);
    violates(fn () => DB::table('audit_log')->update(['action' => 'x.y']), 'append-only');

    $admin = User::where('email', 'loadadmin@load.test')->first();
    $response = $this->actingAs($admin)->getJson('/api/v1/admin/audit-log?per_page=100')->assertOk();
    expect($response->json('meta.total'))->toBe(600);
    $this->getJson('/api/v1/admin/audit-log?action=order.status_changed&from=2020-01-01')->assertOk();
});

it('leaves out audit kinds whose subjects do not exist, and still runs with no orders, quotes or invoices', function () {
    expect(secLoadSeed(['--orders' => 0, '--quotes' => 0, '--invoices' => 0]))->toBe(0);

    expect(DB::table('invoices')->count())->toBe(0)->and(DB::table('quote_requests')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('subject_type', 'order')->count())->toBe(0)->and(DB::table('audit_log')->count())->toBe(600);
});
