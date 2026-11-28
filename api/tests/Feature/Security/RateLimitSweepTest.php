<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\RateLimits;

afterEach(fn () => $this->travelBack());

/** @return array<string, array{array<string, mixed>}> label => [table row], so every row is its own test */
function secRateRows(): array
{
    $rows = [];
    foreach (RateLimits::all() as $entry) {
        $rows[$entry['label'] ?? $entry['name']] = [$entry];
    }

    return $rows;
}

/** Authenticate the caller of a row (guests stay guests); $n picks a member of a pool of users. */
function secRateActAs(object $t, array $entry, int $n = 0): void
{
    if ($entry['as'] === 'guest') {
        return;
    }
    // Kept on the test instance (a new one per test), so a user of an earlier, rolled-back test is never reused.
    $t->secRateUsers ??= [];
    $t->secRateUsers[$entry['as'].$n] ??= makeUser($entry['as']);
    $t->actingAs($t->secRateUsers[$entry['as'].$n]);
}

function secRateFrom(object $t, string $ip): void
{
    $t->withServerVariables(['REMOTE_ADDR' => $ip]);
}

it('declares a limiter for every name in the table', function () {
    foreach (RateLimits::names() as $name) {
        expect(RateLimiter::limiter($name))->not->toBeNull($name);
    }
});

it('enforces the limit of every row: max calls pass, the next is a 429 with Retry-After and the standard body', function (array $entry) {
    $pool = $entry['pool'] ?? 1;
    $burst = $entry['burst'] ?? null;

    for ($i = 1; $i <= $entry['max']; $i++) {
        secRateActAs($this, $entry, ($i - 1) % $pool);
        $status = $entry['call']($i, $this)->getStatusCode();
        expect($status)->not->toBe(429, "call {$i} of {$entry['max']}");
        $burst !== null && $i % $burst === 0 && $i < $entry['max'] && $this->travel(61)->seconds();
    }

    $burst !== null && $this->travel(61)->seconds();
    secRateActAs($this, $entry, $entry['max'] % $pool);
    $blocked = $entry['call']($entry['max'] + 1, $this);

    expect($blocked->getStatusCode())->toBe(429)
        ->and((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($blocked->json('message'))->toMatch('/^Too many attempts\. Please try again in \d+ seconds\.$/');
})->with(fn () => secRateRows());

it('gives another user, or another address, an independent bucket', function (array $entry) {
    if ($entry['bucket'] === 'none') {
        $this->markTestSkipped('Keyed on the submitted email, not on the caller.');
    }

    $pool = $entry['pool'] ?? 1;
    $burst = $entry['burst'] ?? null;
    for ($i = 1; $i <= $entry['max']; $i++) {
        secRateActAs($this, $entry, ($i - 1) % $pool);
        $entry['call']($i, $this);
        $burst !== null && $i % $burst === 0 && $i < $entry['max'] && $this->travel(61)->seconds();
    }
    $burst !== null && $this->travel(61)->seconds();

    if ($entry['bucket'] === 'user') {
        secRateActAs($this, $entry, 50);
    } else {
        secRateActAs($this, $entry, 50);
        secRateFrom($this, '198.51.100.77');
    }

    expect($entry['call']($entry['max'] + 1, $this)->getStatusCode())->not->toBe(429);
})->with(fn () => secRateRows());

it('lets the window pass: the bucket is free again afterwards', function (array $entry) {
    $pool = $entry['pool'] ?? 1;
    $burst = $entry['burst'] ?? null;
    for ($i = 1; $i <= $entry['max']; $i++) {
        secRateActAs($this, $entry, ($i - 1) % $pool);
        $entry['call']($i, $this);
        $burst !== null && $i % $burst === 0 && $i < $entry['max'] && $this->travel(61)->seconds();
    }
    $burst !== null && $this->travel(61)->seconds();
    secRateActAs($this, $entry, $entry['max'] % $pool);
    expect($entry['call']($entry['max'] + 1, $this)->getStatusCode())->toBe(429);

    $this->travel($entry['window'] + 5)->seconds();

    secRateActAs($this, $entry, 0);
    expect($entry['call']($entry['max'] + 2, $this)->getStatusCode())->not->toBe(429);
})->with(fn () => secRateRows());

it('answers a wrong role with 403 every time, never with a 429 (role check runs before the limiter)', function (array $entry) {
    if (! isset($entry['wrong'])) {
        $this->markTestSkipped('Any role may call this route.');
    }

    $user = makeUser($entry['wrong']);
    $this->actingAs($user);

    for ($i = 1; $i <= $entry['max'] + 3; $i++) {
        expect($entry['call']($i, $this)->getStatusCode())->toBe(403, "call {$i}");
    }
})->with(fn () => secRateRows());

it('does not count order-list requests without q against the search limit', function () {
    $customer = makeUser('customer');
    $this->actingAs($customer);

    foreach (range(1, 29) as $i) {
        $this->getJson('/api/v1/orders?q=PO')->assertOk();
    }
    // Without q: not counted (the shared `api` bucket of 60 a minute still is).
    foreach (range(1, 20) as $i) {
        $this->getJson('/api/v1/orders')->assertOk();
    }
    $this->getJson('/api/v1/orders?q=PO')->assertOk();
    $this->getJson('/api/v1/orders?q=PO')->assertStatus(429);
});

it('shares one bucket between POST /orders and POST /quotes/{n}/accept', function () {
    $customer = makeUser('customer');
    $this->actingAs($customer);

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/orders')->assertStatus(422);
        $this->postJson('/api/v1/quotes/QUO-AAAAA-AAAAA/accept', ['revision' => 1])->assertNotFound();
    }

    $this->postJson('/api/v1/orders')->assertStatus(429);
    $this->postJson('/api/v1/quotes/QUO-AAAAA-AAAAA/accept', ['revision' => 1])->assertStatus(429);
});

it('keeps the 403 for a role the route refuses even when the bucket is full', function () {
    $customer = makeUser('customer');
    $this->actingAs($customer);
    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/orders');
    }
    $this->postJson('/api/v1/orders')->assertStatus(429);

    $this->actingAs(makeUser('staff'))->postJson('/api/v1/orders')->assertForbidden();
    expect(User::count())->toBeGreaterThan(1);
});

it('does not count quote-list and invoice-list requests without q, and shares one search bucket between the three lists', function () {
    $this->actingAs(makeUser('customer'));

    foreach (range(1, 10) as $i) {
        $this->getJson('/api/v1/orders?q=PO')->assertOk();
        $this->getJson('/api/v1/quotes?q=QUO')->assertOk();
        $this->getJson('/api/v1/invoices?q=INV')->assertOk();
    }
    // 30 searches in total: the next one is refused on any of the three lists.
    $this->getJson('/api/v1/quotes?q=QUO')->assertStatus(429);
    $this->getJson('/api/v1/invoices?q=INV')->assertStatus(429);
    $this->getJson('/api/v1/orders?q=PO')->assertStatus(429);
});

it('keeps the api bucket (60 a minute) in force for list requests without q', function () {
    $this->actingAs(makeUser('customer'));

    foreach (range(1, 30) as $i) {
        $this->getJson('/api/v1/quotes')->assertOk();
        $this->getJson('/api/v1/invoices')->assertOk();
    }

    $this->getJson('/api/v1/quotes')->assertStatus(429);
});
