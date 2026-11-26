<?php

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;

beforeEach(function () {
    $this->w = orderWorld();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'UTC'));
    // CSRF is only skipped in the "testing" environment; these tests flip the environment.
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

afterEach(function () {
    $this->travelBack();
    app()->detectEnvironment(fn () => 'testing');
});

function realSeller(): array
{
    return [
        'name' => 'Acme Industrial GmbH', 'address_lines' => ['Main Street 1', '10115 Berlin'], 'tax_id' => 'DE123456789', 'email' => 'billing@acme.example',
        'phone' => '+49 30 1234567',
        'bank' => ['account_holder' => 'Acme Industrial GmbH', 'bank_name' => 'Real Bank AG', 'iban' => 'DE89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX'],
        'payment_terms_text' => 'Please pay by bank transfer.', 'footer' => 'Acme Industrial GmbH, Berlin, HRB 12345.',
    ];
}

function inEnv(string $env): void
{
    app()->detectEnvironment(fn () => $env);
}

function expectRefusedAndNothingWritten(object $test): void
{
    $response = issueInvoiceAs($test->w->staff, orderWith($test->w->a, [[1000, 1]]));

    $response->assertUnprocessable()->assertJsonPath('errors.invoice.0', 'Invoicing is not configured.');
    expect(Invoice::count())->toBe(0)->and(DB::table('invoice_number_counters')->count())->toBe(0);
}

it('issues with a fully configured seller in production and staging', function (string $env) {
    config(['invoicing.seller' => realSeller()]);
    inEnv($env);

    issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertCreated()->assertJsonPath('data.seller.name', 'Acme Industrial GmbH');
})->with(['production', 'staging']);

it('refuses any placeholder, in any seller field, in every environment except local and testing', function (string $path, string $value, string $env) {
    $seller = realSeller();
    data_set($seller, $path, $value);
    config(['invoicing.seller' => $seller]);
    inEnv($env);

    expectRefusedAndNothingWritten($this);
})->with(function () {
    $fields = [
        'name' => 'seller name', 'address_lines.1' => 'address line', 'tax_id' => 'tax id', 'email' => 'email', 'bank.iban' => 'iban', 'bank.bic' => 'bic',
        'bank.account_holder' => 'account holder', 'bank.bank_name' => 'bank name', 'footer' => 'footer', 'payment_terms_text' => 'payment text',
    ];
    foreach (['production', 'staging'] as $env) {
        foreach ($fields as $path => $label) {
            foreach (['upper' => 'PLACEHOLDER', 'lower' => 'placeholder', 'mixed inside text' => 'Real Name PlaceHolder Ltd', 'whitespace' => "  PLACEHOLDER\t"] as $variant => $value) {
                yield "{$env} / {$label} / {$variant}" => [$path, $value, $env];
            }
        }
    }
});

it('refuses an empty required seller field or a .invalid email', function (string $path, mixed $value) {
    $seller = realSeller();
    data_set($seller, $path, $value);
    config(['invoicing.seller' => $seller]);
    inEnv('production');

    expectRefusedAndNothingWritten($this);
})->with([
    'empty name' => ['name', ''], 'blank name' => ['name', '   '], 'no address' => ['address_lines', []], 'blank address line' => ['address_lines', ['  ']],
    'empty tax id' => ['tax_id', ''], 'null tax id' => ['tax_id', null], 'empty email' => ['email', ''], 'empty iban' => ['bank.iban', ''], 'empty bic' => ['bank.bic', ' '],
    'empty holder' => ['bank.account_holder', ''], 'empty footer' => ['footer', ''], 'empty payment text' => ['payment_terms_text', ''],
    '.invalid email' => ['email', 'billing@example.invalid'], '.INVALID email with whitespace' => ['email', ' Billing@Example.INVALID '],
]);

it('does not apply the strict guard in local and testing, where placeholders keep the demo working', function (string $env) {
    config(['invoicing.seller' => ['name' => 'PLACEHOLDER SELLER NAME', 'email' => 'billing@example.invalid', 'tax_id' => 'PLACEHOLDER-VAT-ID', 'footer' => '', 'payment_terms_text' => ''] + realSeller()]);
    inEnv($env);

    issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertCreated();
})->with(['local', 'testing']);

it('still refuses an empty seller name in local and testing', function () {
    config(['invoicing.seller.name' => '']);

    expectRefusedAndNothingWritten($this);
});

it('allows a VAT rate of 0 but warns once per issue outside local and testing', function (string $env, bool $warns) {
    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);
    config(['invoicing.seller' => realSeller(), 'invoicing.vat_rate_bp' => 0]);
    inEnv($env);

    try {
        issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertCreated();
        $warnings = collect($handler->getRecords())->filter(fn ($r) => $r->level === Level::Warning && str_contains($r->message, 'VAT rate is 0'))->count();
    } finally {
        Log::getLogger()->popHandler();
    }

    expect($warnings)->toBe($warns ? 1 : 0);
})->with([['production', true], ['staging', true], ['local', false], ['testing', false]]);

it('does not warn when a VAT rate is configured', function () {
    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);
    config(['invoicing.seller' => realSeller(), 'invoicing.vat_rate_bp' => 1900]);
    inEnv('production');

    issueInvoiceAs($this->w->staff, orderWith($this->w->a, [[1000, 1]]))->assertCreated();
    Log::getLogger()->popHandler();

    expect(collect($handler->getRecords())->contains(fn ($r) => str_contains($r->message, 'VAT rate is 0')))->toBeFalse();
});
