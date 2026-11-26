<?php

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->w = orderWorld();
});

afterEach(fn () => $this->travelBack());

function utcTime(string $utc): CarbonImmutable
{
    return CarbonImmutable::parse($utc, 'UTC');
}

function historyRows(Invoice $invoice): array
{
    return DB::table('invoice_status_history')->where('invoice_id', $invoice->id)->orderBy('id')->get()->map(fn ($r) => [$r->from_status, $r->to_status, $r->actor_user_id])->all();
}

// ---------------------------------------------------------------- the command with a frozen clock

it('materialises overdue exactly at midnight UTC after the due date, once, with a system history row', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-11-26')->dueOn('2026-12-26')->create();

    $this->travelTo(utcTime('2026-12-26 23:59:59'));
    Artisan::call('invoices:mark-overdue');
    expect($invoice->fresh()->status->value)->toBe('open')->and(historyRows($invoice))->toHaveCount(1);

    $this->travelTo(utcTime('2026-12-27 00:00:01'));
    expect(Artisan::call('invoices:mark-overdue'))->toBe(0)->and(Artisan::output())->toContain('Marked 1 invoice(s) overdue.');
    expect($invoice->fresh()->status->value)->toBe('overdue')
        ->and(historyRows($invoice))->toBe([[null, 'open', $invoice->created_by_user_id], ['open', 'overdue', null]]);

    // Idempotent: a second run changes nothing and adds no row.
    Artisan::call('invoices:mark-overdue');
    expect(Artisan::output())->toContain('Marked 0 invoice(s) overdue.')->and(historyRows($invoice))->toHaveCount(2);
});

it('never touches paid, void, already overdue or not-yet-due invoices', function () {
    $paid = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-10-01')->dueOn('2026-10-31')->paid('2026-10-20')->create();
    $void = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-10-01')->dueOn('2026-10-31')->void()->create();
    $overdue = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-10-01')->dueOn('2026-10-31')->overdue()->create();
    $notYet = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-11-20')->dueOn('2026-12-31')->create();
    $dueToday = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-11-20')->dueOn('2026-12-01')->create();
    $this->travelTo(utcTime('2026-12-01 12:00:00'));
    $all = collect([$paid, $void, $overdue, $notYet, $dueToday]);
    $before = $all->map(fn ($i) => historyRows($i))->all();

    Artisan::call('invoices:mark-overdue');

    expect($all->map(fn ($i) => $i->fresh()->status->value)->all())->toBe(['paid', 'void', 'overdue', 'open', 'open'])
        ->and($all->map(fn ($i) => historyRows($i))->all())->toEqual($before);
});

it('processes hundreds of invoices in chunks', function () {
    bulkOpenInvoices($this->w->a, 450, '2026-12-26');
    $this->travelTo(utcTime('2026-12-27 00:00:01'));

    Artisan::call('invoices:mark-overdue');

    expect(Invoice::where('status', 'overdue')->count())->toBe(451)->and(Invoice::where('status', 'open')->count())->toBe(0) // 450 bulk rows plus the template
        ->and(DB::table('invoice_status_history')->where('to_status', 'overdue')->whereNull('actor_user_id')->count())->toBe(451);
});

it('is registered in the schedule to run hourly without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'invoices:mark-overdue'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 * * * *')->and($event->withoutOverlapping)->toBeTrue();
});

// ---------------------------------------------------------------- effective status at read time

it('reports an open invoice past its due date as overdue before the command ran, and one due today as open', function () {
    $this->travelTo(utcTime('2026-12-27 09:00:00'));
    $late = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-11-26')->dueOn('2026-12-26')->create();
    $today = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-11-27')->dueOn('2026-12-27')->create();
    loginAs($this->w->a1);

    $a = $this->getJson("/api/v1/invoices/{$late->public_id}")->assertOk()->json('data');
    $b = $this->getJson("/api/v1/invoices/{$today->public_id}")->assertOk()->json('data');

    expect($a['status'])->toBe('overdue')->and($a['days_overdue'])->toBe(1)->and($a['allowed_actions'])->toBe(['download'])
        ->and($b['status'])->toBe('open')->and($b['days_overdue'])->toBe(0)
        ->and($late->fresh()->status->value)->toBe('open'); // nothing was written by the GET
});

it('switches at exactly midnight UTC with a frozen clock', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-11-26')->dueOn('2026-12-26')->create();
    loginAs($this->w->a1);

    $this->travelTo(utcTime('2026-12-26 23:59:59'));
    $this->getJson("/api/v1/invoices/{$invoice->public_id}")->assertJsonPath('data.status', 'open')->assertJsonPath('data.days_overdue', 0);
    $this->travelTo(utcTime('2026-12-27 00:00:00'));
    $this->getJson("/api/v1/invoices/{$invoice->public_id}")->assertJsonPath('data.status', 'overdue')->assertJsonPath('data.days_overdue', 1);
    $this->travelTo(utcTime('2027-01-05 08:00:00'));
    $this->getJson("/api/v1/invoices/{$invoice->public_id}")->assertJsonPath('data.days_overdue', 10);
});

it('filters by the effective status: overdue includes stored and effective overdue, open excludes them', function () {
    $this->travelTo(utcTime('2026-12-27 09:00:00'));
    $a = $this->w->a;
    $effective = Invoice::factory()->forCompany($a)->issuedOn('2026-11-01')->dueOn('2026-12-01')->create();
    $stored = Invoice::factory()->forCompany($a)->issuedOn('2026-10-01')->dueOn('2026-11-01')->overdue()->create();
    $open = Invoice::factory()->forCompany($a)->issuedOn('2026-12-20')->dueOn('2027-01-20')->create();
    $dueToday = Invoice::factory()->forCompany($a)->issuedOn('2026-12-20')->dueOn('2026-12-27')->create();
    $paid = Invoice::factory()->forCompany($a)->issuedOn('2026-10-01')->dueOn('2026-11-01')->paid('2026-10-15')->create();
    $void = Invoice::factory()->forCompany($a)->issuedOn('2026-10-01')->dueOn('2026-11-01')->void()->create();
    $ids = fn (string $query) => collect(loginAs($this->w->a1)->getJson("/api/v1/invoices?{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    $sorted = fn (array $invoices) => collect($invoices)->pluck('public_id')->sort()->values()->all();

    expect($ids('status=overdue'))->toBe($sorted([$effective, $stored]))
        ->and($ids('status=open'))->toBe($sorted([$open, $dueToday]))
        ->and($ids('status=paid'))->toBe($sorted([$paid]))->and($ids('status=void'))->toBe($sorted([$void]))
        ->and($ids('status=open,overdue'))->toBe($sorted([$effective, $stored, $open, $dueToday]))
        ->and($ids('status=paid,void'))->toBe($sorted([$paid, $void]));
});

it('cannot leak another company through the OR group of the status or search filters', function () {
    $this->travelTo(utcTime('2026-12-27 09:00:00'));
    $mine = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-11-01')->dueOn('2026-12-01')->create();
    $theirs = Invoice::factory()->forCompany($this->w->b)->issuedOn('2026-11-01')->dueOn('2026-12-01')->create();
    $theirsStored = Invoice::factory()->forCompany($this->w->b)->issuedOn('2026-10-01')->dueOn('2026-11-01')->overdue()->create();
    loginAs($this->w->a1);

    foreach (['status=overdue', 'status=open,overdue,paid,void', "q={$theirs->number}", "q={$theirs->order_number}", 'status=overdue&q='.substr($theirs->number, -6), "company_id={$this->w->b->id}"] as $query) {
        $ids = collect($this->getJson("/api/v1/invoices?{$query}")->assertOk()->json('data'))->pluck('id');
        expect($ids)->not->toContain($theirs->public_id)->not->toContain($theirsStored->public_id);
    }
    expect(collect($this->getJson('/api/v1/invoices?status=overdue')->json('data'))->pluck('id')->all())->toBe([$mine->public_id]);
});
