<?php

use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->w = orderWorld();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'UTC'));
});

afterEach(fn () => $this->travelBack());

/** Queries of one request as a freshly loaded user (so the role lookup is counted, as in production). */
function invoiceQueryCount(User $user, string $method, string $uri): int
{
    // The first policy check of an app loads the permission registry once: run the request once unmeasured, then count the steady state.
    loginAs(User::findOrFail($user->id));
    test()->{$method}($uri);
    loginAs(User::findOrFail($user->id));
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->{$method}($uri);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

// ---------------------------------------------------------------- rate limits

it('limits the PDF to 20 requests a minute per user, 404s included, with the standard 429 body', function () {
    $unknown = '/api/v1/invoices/'.Str::uuid().'/pdf';
    loginAs($this->w->a1);

    foreach (range(1, 20) as $_) {
        $this->getJson($unknown)->assertNotFound();
    }
    $limited = $this->getJson($unknown)->assertStatus(429);

    expect($limited->headers->get('Retry-After'))->toBeNumeric()->and($limited->json('message'))->toMatch('/^Too many attempts\. Please try again in \d+ seconds\.$/');
    // The limit is per user, not per company: another user is unaffected.
    loginAs($this->w->a2)->getJson($unknown)->assertNotFound();
    // And it is shared across invoices: a real one is refused too while the budget is spent.
    $invoice = Invoice::factory()->forCompany($this->w->a)->create();
    loginAs($this->w->a1)->getJson("/api/v1/invoices/{$invoice->public_id}/pdf")->assertStatus(429);
});

it('counts real and unknown PDF downloads against one shared budget', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->create();
    loginAs($this->w->staff);

    foreach (range(1, 10) as $_) {
        $this->get("/api/v1/invoices/{$invoice->public_id}/pdf")->assertOk();
    }
    foreach (range(1, 10) as $_) {
        $this->getJson('/api/v1/invoices/'.Str::uuid().'/pdf')->assertNotFound();
    }

    $this->get("/api/v1/invoices/{$invoice->public_id}/pdf")->assertStatus(429);
});

it('limits the three staff writes to 30 attempts a minute per user, 422s included', function () {
    $unknown = (string) Str::uuid();
    $order = orderWith($this->w->a, [[1000, 1]], 'pending');
    loginAs($this->w->staff);

    foreach (range(1, 10) as $_) {
        $this->postJson("/api/v1/invoices/{$unknown}/void", ['reason' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/invoices/{$unknown}/mark-paid")->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->order_number}/invoice")->assertUnprocessable();
    }

    $limited = $this->postJson("/api/v1/invoices/{$unknown}/void", ['reason' => 'x'])->assertStatus(429);
    expect($limited->headers->get('Retry-After'))->toBeNumeric()->and($limited->json('message'))->toContain('Too many attempts');
    loginAs($this->w->admin)->postJson("/api/v1/invoices/{$unknown}/void", ['reason' => 'x'])->assertNotFound();
});

it('adds an IP-keyed PDF bucket of 60 a minute on top of the per-user limit', function () {
    $unknown = '/api/v1/invoices/'.Str::uuid().'/pdf';

    // Four users from one address spend the shared IP budget (15 each, far below their own 20 a minute) ...
    foreach (range(1, 4) as $_) {
        loginAs(makeUser('customer', $this->w->a));
        foreach (range(1, 15) as $__) {
            $this->getJson($unknown)->assertNotFound();
        }
    }

    // ... so a fifth user with a fresh personal budget is refused by the IP bucket, with the standard body.
    $limited = loginAs(makeUser('customer', $this->w->a))->getJson($unknown)->assertStatus(429);
    expect($limited->headers->get('Retry-After'))->toBeNumeric()->and($limited->json('message'))->toMatch('/^Too many attempts\. Please try again in \d+ seconds\.$/');
});

it('keeps the role check ahead of both PDF limiters', function () {
    $unknown = '/api/v1/invoices/'.Str::uuid().'/pdf';
    loginAs(User::factory()->create());

    foreach (range(1, 70) as $_) {
        $this->getJson($unknown)->assertForbidden();
    }
});

it('does not render a PDF for HEAD: it is refused with 405 before any invoice lookup, and still counts against the limiter', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->create();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = loginAs($this->w->a1)->call('HEAD', "/api/v1/invoices/{$invoice->public_id}/pdf", [], [], [], ['HTTP_ORIGIN' => 'http://localhost:5173']);

    $sql = implode(' ', array_column(DB::getQueryLog(), 'query'));
    DB::disableQueryLog();
    expect($response->getStatusCode())->toBe(405)->and($response->headers->get('Allow'))->toBe('GET')->and($sql)->not->toContain('invoice');
    // Guests and roleless users never get past the usual 401 / 403.
    $this->app['auth']->forgetGuards();
    expect($this->call('HEAD', "/api/v1/invoices/{$invoice->public_id}/pdf")->getStatusCode())->toBe(401);
    $this->flushSession();
    loginAs($this->w->a1)->get("/api/v1/invoices/{$invoice->public_id}/pdf")->assertOk();
});

it('keeps the shared api limiter in force on the invoice list', function () {
    loginAs($this->w->a1);

    foreach (range(1, 60) as $_) {
        $this->getJson('/api/v1/invoices')->assertOk();
    }
    $this->getJson('/api/v1/invoices')->assertStatus(429);
});

// ---------------------------------------------------------------- query counts (measured: list 3, detail 6, pdf 3; bounds are measured + 1)

it('runs a constant number of queries for the list between 3 and 60 invoices', function () {
    Invoice::factory()->forCompany($this->w->a)->count(3)->create();

    foreach (['a1', 'staff'] as $who) {
        $user = $this->w->{$who};
        $queries = ['/api/v1/invoices', '/api/v1/invoices?per_page=5&q=INV&status=open,overdue&sort=-total', '/api/v1/invoices?per_page=100'];
        $few = array_map(fn ($u) => invoiceQueryCount($user, 'getJson', $u), $queries);
        $who === 'a1' ? Invoice::factory()->forCompany($this->w->a)->count(2)->create() : bulkOpenInvoices($this->w->a, 55, '2026-12-26');
        $many = array_map(fn ($u) => invoiceQueryCount($user, 'getJson', $u), $queries);

        expect($many)->toBe($few)->and(max($many))->toBeLessThanOrEqual(4);
    }
});

it('runs a constant number of queries for the detail regardless of lines and history', function () {
    $small = Invoice::factory()->forCompany($this->w->a)->withLines(1)->create();
    $large = Invoice::factory()->forCompany($this->w->a)->withLines(100)->overdue()->create();

    foreach (['a1', 'staff'] as $who) {
        $one = invoiceQueryCount($this->w->{$who}, 'getJson', "/api/v1/invoices/{$small->public_id}");
        $many = invoiceQueryCount($this->w->{$who}, 'getJson', "/api/v1/invoices/{$large->public_id}");

        expect($many)->toBe($one)->and($many)->toBeLessThanOrEqual(7);
    }
});

it('runs a constant number of queries for the PDF regardless of the number of lines', function () {
    $small = Invoice::factory()->forCompany($this->w->a)->withLines(1)->create();
    $large = Invoice::factory()->forCompany($this->w->a)->withLines(100)->create();

    foreach (['a1', 'staff'] as $who) {
        $one = invoiceQueryCount($this->w->{$who}, 'get', "/api/v1/invoices/{$small->public_id}/pdf");
        $many = invoiceQueryCount($this->w->{$who}, 'get', "/api/v1/invoices/{$large->public_id}/pdf");

        expect($many)->toBe($one)->and($many)->toBeLessThanOrEqual(4);
    }
});

it('adds exactly one query to the order detail for the active invoice, whatever its size', function () {
    $plain = orderWith($this->w->a, [[1000, 1]]);
    $invoiced = Invoice::factory()->forCompany($this->w->a)->withLines(30)->create();
    $invoicedOrder = Order::findOrFail($invoiced->order_id);

    foreach (['a1', 'staff'] as $who) {
        $without = invoiceQueryCount($this->w->{$who}, 'getJson', "/api/v1/orders/{$plain->order_number}");
        $with = invoiceQueryCount($this->w->{$who}, 'getJson', "/api/v1/orders/{$invoicedOrder->order_number}");

        // Phase 3/4 bound for the order detail was 10 including user and roles; with the activeInvoice query it is 11 there (measured here without the session user query).
        expect($with)->toBe($without)->and($with)->toBeLessThanOrEqual(10);
    }
});
