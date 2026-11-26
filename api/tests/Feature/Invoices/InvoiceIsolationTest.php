<?php

use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->w = orderWorld();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'UTC'));
    $this->inv = Invoice::factory()->forCompany($this->w->a)->create();
    $this->foreign = Invoice::factory()->forCompany($this->w->b)->create();
    $this->unknown = (string) Str::uuid();
});

afterEach(fn () => $this->travelBack());

/** Every invoice endpoint: [method, uri template, body, staff only]. {i} = invoice id, {o} = order number. */
function invoiceEndpoints(): array
{
    return [
        'list' => ['getJson', '/api/v1/invoices', [], false],
        'detail' => ['getJson', '/api/v1/invoices/{i}', [], false],
        'pdf' => ['getJson', '/api/v1/invoices/{i}/pdf', [], false],
        'create' => ['postJson', '/api/v1/orders/{o}/invoice', [], true],
        'mark-paid' => ['postJson', '/api/v1/invoices/{i}/mark-paid', [], true],
        'void' => ['postJson', '/api/v1/invoices/{i}/void', ['reason' => 'x'], true],
    ];
}

function invoiceUri(string $uri, string $invoiceId, string $orderNumber = 'ORD-AAAAA-AAAAA'): string
{
    return str_replace(['{i}', '{o}'], [$invoiceId, $orderNumber], $uri);
}

it('answers a guest with 401 on all six endpoints', function (string $name) {
    [$method, $uri, $body] = invoiceEndpoints()[$name];

    $this->{$method}(invoiceUri($uri, $this->inv->public_id, $this->inv->order_number), $body)->assertUnauthorized();
})->with(array_keys(invoiceEndpoints()));

it('answers a customer on a staff endpoint with 403, even for an unknown well-formed id and number', function (string $name) {
    [$method, $uri, $body] = invoiceEndpoints()[$name];
    loginAs($this->w->a1);

    $this->{$method}(invoiceUri($uri, $this->inv->public_id, $this->inv->order_number), $body)->assertForbidden();
    $this->{$method}(invoiceUri($uri, $this->unknown, 'ORD-ZZZZZ-ZZZZZ'), $body)->assertForbidden();
    $this->{$method}(invoiceUri($uri, $this->foreign->public_id, $this->foreign->order_number), $body)->assertForbidden();
})->with(['create', 'mark-paid', 'void']);

it('answers a user without a role with 403 on every role-guarded endpoint and changes nothing', function (string $name) {
    [$method, $uri, $body] = invoiceEndpoints()[$name];
    loginAs(User::factory()->create());

    $this->{$method}(invoiceUri($uri, $this->inv->public_id, $this->inv->order_number), $body)->assertForbidden();
    expect($this->inv->fresh()->status->value)->toBe('open');
})->with(array_keys(invoiceEndpoints()));

it('answers a foreign or unknown invoice with the identical 404 body for a customer', function (string $name) {
    [$method, $uri] = invoiceEndpoints()[$name];
    loginAs($this->w->b1);

    $foreign = $this->{$method}(invoiceUri($uri, $this->inv->public_id), []);
    $unknown = $this->{$method}(invoiceUri($uri, $this->unknown), []);

    $foreign->assertNotFound()->assertExactJson(NOT_FOUND);
    $unknown->assertNotFound()->assertExactJson(NOT_FOUND);
    expect($foreign->getContent())->toBe($unknown->getContent());
})->with(['detail', 'pdf']);

it('lets customers of the same company share invoices and keeps other companies out', function () {
    foreach (['a1', 'a2'] as $who) {
        loginAs($this->w->{$who})->getJson("/api/v1/invoices/{$this->inv->public_id}")->assertOk();
        loginAs($this->w->{$who})->getJson("/api/v1/invoices/{$this->foreign->public_id}")->assertNotFound();
    }
});

it('lists only the viewer company for customers and everything for staff and admin', function () {
    expect(collect(loginAs($this->w->a1)->getJson('/api/v1/invoices')->assertOk()->json('data'))->pluck('id')->all())->toBe([$this->inv->public_id])
        ->and(collect(loginAs($this->w->b1)->getJson('/api/v1/invoices')->json('data'))->pluck('id')->all())->toBe([$this->foreign->public_id]);

    foreach (['staff', 'admin'] as $who) {
        expect(collect(loginAs($this->w->{$who})->getJson('/api/v1/invoices')->json('data'))->pluck('id')->sort()->values()->all())
            ->toBe(collect([$this->inv, $this->foreign])->pluck('public_id')->sort()->values()->all());
    }
});

it('never lists a foreign invoice through any filter', function (string $query) {
    $query = str_replace(['{n}', '{o}', '{b}'], [$this->foreign->number, $this->foreign->order_number, $this->w->b->id], $query);

    $ids = collect(loginAs($this->w->a1)->getJson("/api/v1/invoices?{$query}")->assertOk()->json('data'))->pluck('id');

    expect($ids)->not->toContain($this->foreign->public_id);
})->with(['q={n}', 'q={o}', 'status=open', 'status=open,overdue,paid,void', 'company_id={b}', 'from=2000-01-01&to=2100-01-01', 'sort=-total&per_page=100']);

it('applies the company_id filter to staff and ignores it for customers', function () {
    expect(collect(loginAs($this->w->staff)->getJson("/api/v1/invoices?company_id={$this->w->b->id}")->assertOk()->json('data'))->pluck('id')->all())->toBe([$this->foreign->public_id])
        ->and(loginAs($this->w->staff)->getJson('/api/v1/invoices?company_id=999999')->assertOk()->json('meta.total'))->toBe(0)
        ->and(loginAs($this->w->staff)->getJson('/api/v1/invoices?company_id=abc')->assertUnprocessable()->json('errors'))->toHaveKey('company_id');

    $mine = loginAs($this->w->a1)->getJson("/api/v1/invoices?company_id={$this->w->b->id}")->assertOk();
    expect($mine->json('data.*.id'))->toBe([$this->inv->public_id]);
    loginAs($this->w->a1)->getJson('/api/v1/invoices?company_id=abc')->assertOk();
});

it('shows a customer without a company nothing and answers detail and PDF with 404', function () {
    $orphan = makeUser('customer', $this->w->a);
    $orphan->forceFill(['company_id' => null])->save();
    loginAs($orphan);

    $this->getJson('/api/v1/invoices')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    $this->getJson("/api/v1/invoices/{$this->inv->public_id}")->assertNotFound();
    $this->getJson("/api/v1/invoices/{$this->inv->public_id}/pdf")->assertNotFound();
});

it('lets staff act on any company and a customer never write', function () {
    loginAs($this->w->staff)->postJson("/api/v1/invoices/{$this->foreign->public_id}/mark-paid")->assertOk();
    loginAs($this->w->staff)->postJson('/api/v1/invoices/'.$this->unknown.'/void', ['reason' => 'x'])->assertNotFound()->assertExactJson(NOT_FOUND);
    loginAs($this->w->staff)->postJson('/api/v1/orders/ORD-ZZZZZ-ZZZZZ/invoice')->assertNotFound()->assertExactJson(NOT_FOUND);
    loginAs($this->w->staff)->postJson('/api/v1/orders/not-an-order/invoice')->assertNotFound();

    expect($this->foreign->fresh()->status->value)->toBe('paid')->and($this->inv->fresh()->status->value)->toBe('open');
});

it('has no route to edit or delete an invoice or to create one outside an order (405)', function () {
    loginAs($this->w->admin);

    $this->postJson('/api/v1/invoices', [])->assertStatus(405);
    foreach (['putJson', 'patchJson', 'deleteJson'] as $method) {
        $this->{$method}("/api/v1/invoices/{$this->inv->public_id}", [])->assertStatus(405);
    }
    $this->deleteJson("/api/v1/invoices/{$this->inv->public_id}/pdf")->assertStatus(405);
    expect(Invoice::count())->toBe(2);
});

it('keeps the role check ahead of the write limiter: a customer hammering a staff route gets 403, never 429', function () {
    loginAs($this->w->a1);

    foreach (range(1, 70) as $_) {
        $this->postJson("/api/v1/invoices/{$this->inv->public_id}/void", ['reason' => 'x'])->assertForbidden();
    }
});

it('only reaches the Invoice model through visibleTo in controllers', function () {
    $sources = collect(glob(app_path('Http/Controllers/Api/V1/*.php')))->mapWithKeys(fn ($f) => [basename($f) => file_get_contents($f)]);

    foreach ($sources as $file => $code) {
        // Any static call on Invoice other than visibleTo() (query, where, find, all, create ...) or ::class bypasses the company boundary.
        preg_match('/\bInvoice(Item|StatusHistory)?::(?!visibleTo\b|class\b)\w+/', $code, $m) === 0 || test()->fail("{$file} touches the invoice model without visibleTo(): {$m[0]}");
    }
    expect($sources['InvoiceController.php'])->toContain('Invoice::visibleTo(')->and($sources['InvoiceController.php'])->toContain('Order::visibleTo(');
});
