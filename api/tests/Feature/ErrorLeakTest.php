<?php

use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

const SECRETS = ['XX99 SECRET IBAN', 'SECRET STREET 1', 'SECRET BUYER LTD', 'SECRET-TAX-ID', 'SECRET-PO-123'];

beforeEach(function () {
    $this->w = orderWorld();
    // Capture everything the real default channel receives (what would otherwise end up in laravel.log).
    $this->logs = new TestHandler;
    Log::getLogger()->pushHandler($this->logs);
    config(['invoicing.seller.bank.iban' => 'XX99 SECRET IBAN']);
    $this->w->a->forceFill(['name' => 'SECRET BUYER LTD', 'billing_address' => "SECRET STREET 1\nSecretville", 'tax_id' => 'SECRET-TAX-ID'])->save();
});

afterEach(fn () => Log::getLogger()->popHandler());

/** Everything logged so far as one string (message, context, extra). */
function loggedText(TestHandler $handler): string
{
    return json_encode(array_map(fn ($r) => [$r->message, $r->context, $r->extra], $handler->getRecords()), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

function expectNoSecrets(string $text): void
{
    foreach (SECRETS as $secret) {
        expect($text)->not->toContain($secret);
    }
}

it('neither logs nor returns bound values when an invoice insert violates a constraint', function (bool $debug) {
    config(['app.debug' => $debug]);
    // A real violation on the real insert: the snapshot carries the secrets and the row is refused by the database.
    DB::statement("ALTER TABLE invoices ADD CONSTRAINT tmp_refuse_seller CHECK ((seller->>'name') <> 'Test Seller GmbH')");
    $order = orderWith($this->w->a, [[1000, 1]], 'confirmed', ['po_reference' => 'SECRET-PO-123']);

    $response = issueInvoiceAs($this->w->staff, $order);

    $response->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
    expectNoSecrets($response->getContent());
    expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('insert into')->not->toContain('tmp_refuse_seller');
    expectNoSecrets(loggedText($this->logs));
    // The failure itself is still logged, with the SQL placeholders and the SQLSTATE but no values.
    $record = collect($this->logs->getRecords())->first(fn ($r) => ($r->context['sqlstate'] ?? null) === '23514');
    expect($record)->not->toBeNull()->and($record->context['sql'])->toContain('insert into "invoices"')->not->toContain('SECRET')
        ->and($record->context)->toHaveKeys(['sqlstate', 'sql', 'connection', 'code']);
    expect(Invoice::count())->toBe(0);
})->with([true, false]);

it('applies the same rule to other domains: a unique violation on orders leaks nothing', function (bool $debug) {
    config(['app.debug' => $debug]);
    DB::statement('CREATE UNIQUE INDEX tmp_unique_po ON orders (po_reference)');
    $product = makeProduct('LEAK-1', ['price_cents' => 1000]);
    fillCart($this->w->a1, [[$product, 1]]);
    $this->actingAs($this->w->a1)->postJson('/api/v1/orders', ['po_reference' => 'SECRET-PO-123'])->assertCreated();
    fillCart($this->w->a1, [[$product, 1]]);

    $response = $this->postJson('/api/v1/orders', ['po_reference' => 'SECRET-PO-123']);

    $response->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
    expectNoSecrets($response->getContent());
    expectNoSecrets(loggedText($this->logs));
    expect(collect($this->logs->getRecords())->contains(fn ($r) => ($r->context['sqlstate'] ?? null) === '23505'))->toBeTrue()
        ->and(Order::count())->toBe(1);
})->with([true, false]);

it('keeps other exception rendering and the exact 404 body unchanged', function () {
    config(['app.debug' => true]);

    $this->actingAs($this->w->a1)->getJson('/api/v1/orders/ORD-ZZZZZ-ZZZZZ')->assertNotFound()->assertExactJson(NOT_FOUND);
    $this->postJson('/api/v1/orders', ['po_reference' => str_repeat('x', 200)])->assertUnprocessable();
});
