<?php

use App\Models\Invoice;
use Illuminate\Support\Carbon;

beforeEach(function () {
    config(['catalog.currency' => 'USD']);
    $this->travelTo(Carbon::parse('2026-11-27 10:15:00', 'UTC')); // a Friday; the ISO week started on Monday 2026-11-23
    $this->staff = makeUser('staff');
    $this->admin = makeUser('admin');
    $this->w = quoteWorld();
});

afterEach(fn () => $this->travelBack());

function dashboardData(?object $as = null): array
{
    return test()->actingAs($as ?? test()->staff)->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
}

function dashboardInvoice(int $total, string $due, string $state = 'open'): Invoice
{
    $order = orderWith(test()->w->a, [[$total, 1]], 'confirmed');

    return Invoice::factory()->forOrder($order)->{$state}()->issuedOn('2026-10-01')->dueOn($due)->create();
}

it('returns the contract shape with zeros on an empty system', function () {
    $response = $this->actingAs($this->staff)->getJson('/api/v1/admin/dashboard')->assertOk();

    expect($response->json('data'))->toBe([
        'generated_at' => '2026-11-27T10:15:00Z', 'week_start' => '2026-11-23', 'currency' => 'USD',
        'quotes' => ['open' => 0, 'awaiting_customer' => 0, 'expiring_soon' => 0],
        'orders' => ['this_week' => 0, 'pending' => 0],
        'invoices' => ['overdue_count' => 0, 'overdue_total_cents' => 0, 'due_soon_count' => 0, 'due_soon_total_cents' => 0],
        'products' => ['inactive' => 1],
    ]);
});

it('counts quotes: open, awaiting the customer and expiring within 7 days', function () {
    $w = $this->w;
    makeQuote($w, 'submitted');
    makeQuote($w, 'inReview');
    makeQuote($w, 'accepted');
    makeQuote($w, 'declined');
    makeQuote($w, 'expired');
    makeQuote($w, 'quoted', ['valid_until' => '2026-11-26']); // stored quoted, past valid_until: expired, not awaiting
    makeQuote($w, 'quoted', ['valid_until' => '2026-11-27']); // valid through today: awaiting and expiring
    makeQuote($w, 'quoted', ['valid_until' => '2026-12-04']); // today + 7: still expiring
    makeQuote($w, 'quoted', ['valid_until' => '2026-12-05']); // today + 8: awaiting only

    expect(dashboardData()['quotes'])->toBe(['open' => 2, 'awaiting_customer' => 3, 'expiring_soon' => 2]);
});

it('counts orders of any status created this ISO week, and all pending ones', function () {
    $c = $this->w->a;
    orderWith($c, [[100, 1]], 'pending', ['created_at' => '2026-11-22 23:59:59']); // Sunday before the week: pending, not this week
    orderWith($c, [[100, 1]], 'delivered', ['created_at' => '2026-11-23 00:00:00']);
    orderWith($c, [[100, 1]], 'cancelled', ['created_at' => '2026-11-26 09:00:00']);
    orderWith($c, [[100, 1]], 'pending', ['created_at' => '2026-11-27 09:00:00']);
    orderWith($c, [[100, 1]], 'shipped', ['created_at' => '2026-11-10 09:00:00']);

    expect(dashboardData()['orders'])->toBe(['this_week' => 3, 'pending' => 2]);
});

it('starts the week on Monday 00:00 UTC exactly', function () {
    $c = $this->w->a;
    orderWith($c, [[100, 1]], 'confirmed', ['created_at' => '2026-11-29 23:59:59']);
    orderWith($c, [[100, 1]], 'confirmed', ['created_at' => '2026-11-30 00:00:00']);
    orderWith($c, [[100, 1]], 'confirmed', ['created_at' => '2026-11-23 00:00:00']);
    orderWith($c, [[100, 1]], 'confirmed', ['created_at' => '2026-11-22 23:59:59']);

    $this->travelTo(Carbon::parse('2026-11-29 23:59:59', 'UTC')); // Sunday: still the week of 2026-11-23
    $sunday = dashboardData();
    $this->travelTo(Carbon::parse('2026-11-30 00:00:00', 'UTC')); // Monday: a new week
    $monday = dashboardData();

    expect($sunday['week_start'])->toBe('2026-11-23')->and($sunday['orders']['this_week'])->toBe(3)
        ->and($monday['week_start'])->toBe('2026-11-30')->and($monday['orders']['this_week'])->toBe(1);
});

it('counts invoices by effective status with their sums', function () {
    dashboardInvoice(1000, '2026-11-26'); // stored open, past due: overdue before the command ran
    dashboardInvoice(2000, '2026-11-27'); // due today: not overdue, due soon
    dashboardInvoice(3000, '2026-12-04'); // today + 7: due soon
    dashboardInvoice(4000, '2026-12-05'); // today + 8: neither
    dashboardInvoice(5000, '2026-11-01', 'overdue'); // stored overdue
    dashboardInvoice(6000, '2026-11-01', 'paid');
    dashboardInvoice(7000, '2026-11-01', 'void');

    expect(dashboardData()['invoices'])->toBe(['overdue_count' => 2, 'overdue_total_cents' => 6000, 'due_soon_count' => 2, 'due_soon_total_cents' => 5000]);
});

it('counts inactive products', function () {
    makeProduct('IN-1', ['active' => false]);
    makeProduct('IN-2', ['active' => false]);
    makeProduct('AC-1');

    expect(dashboardData()['products'])->toBe(['inactive' => 3]); // plus the inactive one of quoteWorld()
});

it('gives staff and admin the same payload', function () {
    makeQuote($this->w, 'submitted');
    dashboardInvoice(1000, '2026-11-26');

    expect(dashboardData($this->admin))->toBe(dashboardData($this->staff));
});

it('is never cached: a status change shows at once', function () {
    $order = orderWith($this->w->a, [[100, 1]], 'pending');
    expect(dashboardData()['orders']['pending'])->toBe(1);

    $this->actingAs($this->staff)->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => 'confirmed'])->assertOk();

    expect(dashboardData()['orders']['pending'])->toBe(0);
});

it('sums money exactly beyond 32 bits', function () {
    foreach ([900_000_000, 900_000_000, 900_000_000] as $i => $total) {
        dashboardInvoice($total, '2026-11-01');
    }

    expect(dashboardData()['invoices']['overdue_total_cents'])->toBe(2_700_000_000);
});

it('runs four aggregate queries however many rows there are', function () {
    $this->actingAs($this->staff);
    $count = fn () => adminQueryCount(fn () => $this->getJson('/api/v1/admin/dashboard')->assertOk());
    $count(); // warm-up
    foreach (range(1, 3) as $i) {
        orderWith($this->w->a, [[100, 1]], 'pending');
    }
    $few = $count();
    bulkOpenInvoices($this->w->a, 300, '2026-11-01');

    $many = $count();

    expect($many)->toBe($few)->and($few)->toBe(4); // the four aggregates
});

it('is forbidden for customers and unauthorized for guests', function () {
    $this->actingAs($this->w->a1)->getJson('/api/v1/admin/dashboard')->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
});
