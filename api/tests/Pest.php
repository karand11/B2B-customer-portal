<?php

use App\Models\CartItem;
use App\Models\Category;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\QuoteRequest;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

const TEST_PASSWORD = 'Demo-Passw0rd!';

const NOT_FOUND = ['message' => 'Not found.'];

const DETAIL_KEYS = [
    'number', 'status', 'currency', 'subtotal_cents', 'tax_cents', 'shipping_cents', 'total_cents', 'line_count', 'po_reference', 'quote_number', 'invoice', 'can_invoice',
    'company', 'placed_by', 'created_at', 'updated_at', 'items', 'history', 'allowed_transitions',
];

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed(RolesSeeder::class);
        config(['invoicing' => testInvoicingConfig()]);
        // Stateful (SPA cookie) mode needs an allowed Origin; always ask for JSON.
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Accept' => 'application/json']);
    })
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/** Create a factory user with the given role (customer gets a company, staff/admin none). */
function makeUser(string $role = 'customer', ?Company $company = null, array $attributes = []): User
{
    $factory = User::factory();

    return match ($role) {
        'customer' => $factory->customer($company)->create($attributes),
        'staff' => $factory->staff()->create($attributes),
        'admin' => $factory->admin()->create($attributes),
    };
}

/** Category by slug, created on first use (shared by catalog tests). */
function category(string $slug = 'deep-groove-ball'): Category
{
    return Category::where('slug', $slug)->first()
        ?? Category::factory()->create(['slug' => $slug, 'name' => ucfirst(str_replace('-', ' ', $slug))]);
}

/** Active product with explicit values; category defaults to deep-groove-ball. */
function makeProduct(string $partNumber, array $attributes = []): Product
{
    $attributes += ['category_id' => category()->id];

    return Product::factory()->create(['part_number' => $partNumber, 'name' => "Bearing {$partNumber}"] + $attributes);
}

/** @return list<string> */
function partNumbers(TestResponse $response): array
{
    return array_column($response->json('data'), 'part_number');
}

function loginAs(User $user): TestCase
{
    return test()->actingAs($user);
}

/**
 * Carry the session cookie of a response into following requests (the test client has no cookie jar).
 * Returns the plain session id.
 */
function carrySession(TestResponse $response): string
{
    $name = config('session.cookie');
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $name);
    expect($cookie)->not->toBeNull();

    $id = CookieValuePrefix::remove(decrypt($cookie->getValue(), false));
    test()->withCookie($name, $id);

    return $id;
}

/** Standard Phase 3 fixture: company A (a1, a2), company B (b1), one staff and one admin. */
function orderWorld(): object
{
    $a = Company::factory()->create(['name' => 'Northwind Test Ltd']);
    $b = Company::factory()->create(['name' => 'Globex Test Ltd']);

    return (object) [
        'a' => $a, 'b' => $b,
        'a1' => makeUser('customer', $a), 'a2' => makeUser('customer', $a), 'b1' => makeUser('customer', $b),
        'staff' => makeUser('staff'), 'admin' => makeUser('admin'),
    ];
}

/**
 * Put lines straight into a user's cart.
 *
 * @param  list<array{Product, int}>  $lines
 */
function fillCart(User $user, array $lines): void
{
    foreach ($lines as [$product, $quantity]) {
        CartItem::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => $quantity]);
    }
}

/** A pending order of the user's company with the given [product, quantity] lines, created through the real endpoint. */
function placeOrderAs(User $user, array $lines, array $body = []): Order
{
    fillCart($user, $lines);
    $number = test()->actingAs($user)->postJson('/api/v1/orders', $body)->assertCreated()->json('data.number');

    return Order::where('order_number', $number)->firstOrFail();
}

/** Exact keys of a quote in a list (every viewer) and in a detail (customer view; staff add internal_note after staff_note). */
const QUOTE_KEYS = [
    'number', 'status', 'currency', 'line_count', 'po_reference', 'subtotal_cents', 'tax_cents', 'shipping_cents', 'total_cents',
    'valid_until', 'company', 'requested_by', 'created_at', 'updated_at',
];

const QUOTE_DETAIL_KEYS = [
    ...QUOTE_KEYS, 'customer_note', 'staff_note', 'revision', 'expires_at', 'items', 'history', 'attachments', 'order', 'allowed_actions',
];

/** Standard Phase 4 fixture: the Phase 3 world plus a priced, a price-on-request, an out-of-stock and an inactive product. */
function quoteWorld(): object
{
    $w = orderWorld();
    $w->priced = makeProduct('QT-100', ['price_cents' => 1250]);
    $w->por = makeProduct('QT-POR', ['price_cents' => null, 'price_on_request' => true]);
    $w->oos = makeProduct('QT-OOS', ['stock_status' => 'out_of_stock']);
    $w->inactive = makeProduct('QT-OFF', ['active' => false]);

    return $w;
}

/** A quote of company A created by a1 with two lines ([priced product, 10], [custom, 4]) in the given factory state. */
function makeQuote(object $w, string $state = 'submitted', array $attributes = []): QuoteRequest
{
    return QuoteRequest::factory()->forCompany($w->a, $w->a1)->{$state}()
        ->withLines([[$w->priced, 10, null], ['Taper roller bearing 32208, SKF or equivalent', 4, null]])
        ->create($attributes);
}

function pdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

function pngBytes(): string
{
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0)).$chunk('IDAT', "x\x9cc`\x00\x00\x00\x02\x00\x01").$chunk('IEND', '');
}

function jpegBytes(): string
{
    return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
}

/** A PDF of exactly $bytes bytes (header plus padding). */
function pdfOfSize(int $bytes): string
{
    return pdfBytes().str_repeat('A', $bytes - strlen(pdfBytes()));
}

function fakeUpload(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

/** POST one file as multipart to the quote's attachments endpoint (as whoever is logged in). */
function uploadTo(string $number, UploadedFile $file): TestResponse
{
    return test()->post("/api/v1/quotes/{$number}/attachments", ['file' => $file]);
}

/** Exact keys of the back-office resources (shared by the Admin tests). */
const ADMIN_USER_KEYS = ['id', 'name', 'email', 'role', 'company', 'status', 'invited_at', 'invitation_accepted_at', 'created_at', 'allowed_actions'];

const ADMIN_COMPANY_KEYS = ['id', 'name', 'tax_id', 'has_billing_address', 'version', 'users_count', 'open_orders_count', 'open_quotes_count', 'unpaid_invoices_count', 'created_at'];

const ADMIN_PRODUCT_KEYS = [
    'id', 'part_number', 'name', 'description', 'brand', 'category', 'inner_diameter_mm', 'outer_diameter_mm', 'width_mm', 'material', 'seal_type',
    'price_cents', 'price_on_request', 'stock_status', 'active', 'version', 'created_at', 'updated_at',
];

/** Exact keys of an invoice in a list and in a detail (create, mark-paid and void return the detail). */
const INVOICE_KEYS = [
    'id', 'number', 'status', 'currency', 'subtotal_cents', 'vat_rate_bp', 'tax_cents', 'total_cents', 'issue_date', 'due_date', 'paid_on',
    'days_overdue', 'order_number', 'po_reference', 'line_count', 'company', 'created_at',
];

const INVOICE_DETAIL_KEYS = [...INVOICE_KEYS, 'seller', 'buyer', 'items', 'history', 'allowed_actions'];

const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

/** Fixed invoicing config for every test, so results never depend on the developer's .env (Linux CI hygiene). */
function testInvoicingConfig(): array
{
    return [
        'vat_rate_bp' => 0,
        'vat_label' => 'VAT',
        'payment_terms_days' => 30,
        'max_due_days' => 120,
        'eligible_order_statuses' => ['confirmed', 'shipped', 'delivered'],
        'seller' => [
            'name' => 'Test Seller GmbH',
            'address_lines' => ['Seller Street 1', '10115 Sellertown', 'Sellerland'],
            'tax_id' => 'SELLER-TAX-1',
            'email' => 'billing@seller.test',
            'phone' => '+49 30 000000',
            'bank' => ['account_holder' => 'Test Seller GmbH', 'bank_name' => 'Test Bank', 'iban' => 'XX00 TEST IBAN', 'bic' => 'TESTBIC'],
            'payment_terms_text' => 'Pay by bank transfer.',
            'footer' => 'Test Seller GmbH, registered office Sellertown.',
        ],
    ];
}

/**
 * An order of the company with [unit price cents, quantity] lines (one fresh product per line), created through the factory.
 *
 * @param  list<array{int, int}>  $lines
 */
function orderWith(Company $company, array $lines, string $status = 'confirmed', array $attributes = []): Order
{
    static $n = 0;
    $products = [];
    foreach ($lines as [$price, $quantity]) {
        $products[] = [makeProduct(sprintf('IVT-%05d', ++$n), ['price_cents' => $price]), $quantity];
    }

    return Order::factory()->{$status}()->forCompany($company)->withLines($products)->create($attributes);
}

/** POST /orders/{number}/invoice as the given user. */
function issueInvoiceAs(User $user, Order|string $order, array $body = []): TestResponse
{
    $number = $order instanceof Order ? $order->order_number : $order;

    return test()->actingAs($user)->postJson("/api/v1/orders/{$number}/invoice", $body);
}

/** The most common invoice fixture: company A order with default catalog lines, invoiced by the factory. */
function makeInvoice(Company $company, string $state = 'open', array $attributes = []): Invoice
{
    return Invoice::factory()->forCompany($company)->{$state}()->create($attributes);
}

/** Run $statement inside a savepoint and expect the database to reject it, with $needle in the error (a constraint or trigger name or message). */
function violates(Closure $statement, string $needle): void
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain($needle);

        return;
    }

    test()->fail("The database accepted a row that violates [{$needle}].");
}

/** @return list<array<string, mixed>> every audit row, oldest first, with changes and metadata decoded */
function auditLogRows(?string $action = null): array
{
    return DB::table('audit_log')->when($action, fn ($q) => $q->where('action', $action))->orderBy('id')->get()
        ->map(fn ($r) => (array) $r)
        ->map(fn (array $r) => array_replace($r, ['changes' => json_decode($r['changes'], true), 'metadata' => json_decode($r['metadata'], true)]))
        ->all();
}

/** The single row of an action (the test fails when there are none or several). */
function auditLogOne(string $action): array
{
    $rows = auditLogRows($action);
    expect($rows)->toHaveCount(1, "audit rows of [{$action}]");

    return $rows[0];
}

/** Assert every column of a row except the id and the timestamp. */
function auditLogExpect(array $row, User $actor, string $type, string $subjectId, ?int $companyId, array $changes, array $metadata = []): void
{
    expect($row['actor_user_id'])->toBe($actor->id)
        ->and($row['actor_role'])->toBe($actor->primaryRole())
        ->and($row['subject_type'])->toBe($type)
        ->and($row['subject_id'])->toBe($subjectId)
        ->and($row['company_id'])->toBe($companyId)
        ->and($row['changes'])->toEqual($changes)
        ->and($row['metadata'])->toEqual($metadata);
}

function auditLogStatus(string $from, string $to): array
{
    return ['before' => ['status' => $from], 'after' => ['status' => $to]];
}

/** A valid POST/PUT body; every test overrides only what it tests. */
function adminProductBody(array $overrides = []): array
{
    return array_replace([
        'category_id' => category()->id, 'part_number' => '6205-2RS', 'name' => 'Deep groove ball bearing 6205-2RS 25x52x15', 'description' => null,
        'brand' => 'SKF', 'inner_diameter_mm' => 25, 'outer_diameter_mm' => 52, 'width_mm' => 15, 'material' => 'Chrome steel', 'seal_type' => '2rs',
        'stock_status' => 'in_stock', 'price_on_request' => false, 'price_cents' => 1250,
    ], $overrides);
}

/** Number of queries the callback runs (the caller warms up first: the first request of a test also pays one-time costs such as loading the user's roles). */
function adminQueryCount(Closure $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** The columns of an existing invoice row (without id) with overrides, for raw inserts that must hit a constraint. */
function invoiceRow(Invoice $invoice, array $overrides = []): array
{
    $row = (array) DB::table('invoices')->where('id', $invoice->id)->first();
    unset($row['id']);

    return array_merge($row, $overrides);
}

/** $n open invoices for company $company, all due on $due, inserted with two statements (a few hundred rows through the factory would be slow). */
function bulkOpenInvoices(Company $company, int $n, string $due): void
{
    $template = Invoice::factory()->forCompany($company)->issuedOn('2026-11-01')->dueOn($due)->create();
    $userId = $template->created_by_user_id;
    DB::statement("INSERT INTO orders (order_number, company_id, created_by_user_id, status, currency, subtotal_cents, tax_cents, shipping_cents, total_cents, created_at, updated_at)
        SELECT 'ORD-ZZZZZ-' || translate(lpad(to_hex(g), 5, '0'), '0123456789abcdef', 'ABCDEFGHJKMNPQRS'), ?, ?, 'confirmed', 'USD', 1000, 0, 0, 1000, now(), now()
        FROM generate_series(1, ?) g", [$company->id, $userId, $n]);
    DB::statement("INSERT INTO invoices (public_id, number, number_year, number_seq, company_id, order_id, order_number, created_by_user_id, status, issue_date, due_date, currency,
            vat_rate_bp, subtotal_cents, tax_cents, total_cents, seller, buyer, created_at, updated_at)
        SELECT gen_random_uuid(), 'INV-2026-' || lpad((1000 + o.rn)::text, 6, '0'), 2026, 1000 + o.rn, o.company_id, o.id, o.order_number, o.created_by_user_id, 'open', '2026-11-01', ?::date, 'USD',
            0, 1000, 0, 1000, ?::jsonb, ?::jsonb, now(), now()
        FROM (SELECT o.*, row_number() OVER (ORDER BY o.id) AS rn FROM orders o WHERE o.order_number LIKE 'ORD-ZZZZZ-%') o", [$due, json_encode($template->seller), json_encode($template->buyer)]);
}
