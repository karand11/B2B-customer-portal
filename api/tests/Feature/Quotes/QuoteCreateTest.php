<?php

use App\Mail\QuoteSubmittedMail;
use App\Models\QuoteAttachment;
use App\Models\QuoteItem;
use App\Models\QuoteRequest;
use App\Models\QuoteStatusHistory;
use App\Support\QuoteNumber;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Mail::fake();
    $this->w = quoteWorld();
});

function createQuote(array $body): TestResponse
{
    return test()->postJson('/api/v1/quotes', $body);
}

it('creates a quote with a catalog line and a free-text line and shows it only to its company and staff', function () {
    $w = $this->w;
    $response = loginAs($w->a1)->postJson('/api/v1/quotes', [
        'po_reference' => ' PO-4711/A ',
        'customer_note' => "Need CE certificates please\nThank you",
        'lines' => [
            ['product_id' => $w->priced->id, 'quantity' => 10],
            ['description' => 'Taper roller bearing 32208, SKF or equivalent', 'quantity' => 4],
        ],
    ])->assertCreated();

    $number = $response->json('data.number');
    expect($number)->toMatch('/^QUO-[A-HJKMNP-TV-Z2-9]{5}-[A-HJKMNP-TV-Z2-9]{5}$/D')
        ->and(array_keys($response->json('data')))->toBe(QUOTE_DETAIL_KEYS)
        ->and($response->json('data.status'))->toBe('submitted')
        ->and($response->json('data.po_reference'))->toBe('PO-4711/A')
        ->and($response->json('data.customer_note'))->toBe("Need CE certificates please\nThank you")
        ->and($response->json('data.company'))->toBe(['id' => $w->a->id, 'name' => 'Northwind Test Ltd'])
        ->and($response->json('data.requested_by'))->toBe(['name' => $w->a1->name])
        ->and($response->json('data.line_count'))->toBe(2)
        ->and($response->json('data.currency'))->toBe('USD')
        ->and($response->json('data.subtotal_cents'))->toBeNull()
        ->and($response->json('data.total_cents'))->toBeNull()
        ->and($response->json('data.valid_until'))->toBeNull()
        ->and($response->json('data.revision'))->toBeNull()
        ->and($response->json('data.order'))->toBeNull()
        ->and($response->json('data.allowed_actions'))->toBe(['decline', 'attach'])
        ->and($response->json('data.items'))->toBe([
            ['position' => 1, 'kind' => 'catalog', 'product_id' => $w->priced->id, 'part_number' => 'QT-100', 'name' => 'Bearing QT-100', 'brand' => 'SKF',
                'quantity' => 10, 'unit_price_cents' => null, 'line_total_cents' => null],
            ['position' => 2, 'kind' => 'custom', 'product_id' => null, 'part_number' => null, 'name' => 'Taper roller bearing 32208, SKF or equivalent', 'brand' => null,
                'quantity' => 4, 'unit_price_cents' => null, 'line_total_cents' => null],
        ])
        ->and($response->json('data.history'))->toHaveCount(1)
        ->and($response->json('data.history.0'))->toMatchArray(['from_status' => null, 'to_status' => 'submitted', 'actor' => ['name' => $w->a1->name, 'role' => 'customer'], 'note' => null]);

    $quote = QuoteRequest::where('quote_number', $number)->firstOrFail();
    expect($quote->company_id)->toBe($w->a->id)->and($quote->created_by_user_id)->toBe($w->a1->id)
        ->and($quote->tax_cents)->toBe(0)->and($quote->shipping_cents)->toBe(0)
        ->and(QuoteStatusHistory::where('quote_request_id', $quote->id)->count())->toBe(1);

    // Same company (a2) reads it in the list and in the detail; staff and admin see it too.
    loginAs($w->a2)->getJson('/api/v1/quotes')->assertOk()->assertJsonPath('data.0.number', $number)->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/quotes/{$number}")->assertOk();
    loginAs($w->staff)->getJson("/api/v1/quotes/{$number}")->assertOk();
    loginAs($w->admin)->getJson("/api/v1/quotes/{$number}")->assertOk();

    // Another company never sees it.
    loginAs($w->b1)->getJson('/api/v1/quotes')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
    $this->getJson("/api/v1/quotes/{$number}")->assertNotFound()->assertExactJson(NOT_FOUND);
});

it('keeps the snapshot when the product changes or is deactivated later', function () {
    $w = $this->w;
    $number = loginAs($w->a1)->postJson('/api/v1/quotes', ['lines' => [['product_id' => $w->priced->id, 'quantity' => 1]]])->assertCreated()->json('data.number');

    $w->priced->forceFill(['name' => 'Renamed bearing', 'brand' => 'FAG', 'part_number' => 'QT-999', 'price_cents' => 99999, 'active' => false])->save();

    $item = $this->getJson("/api/v1/quotes/{$number}")->assertOk()->json('data.items.0');
    expect($item)->toMatchArray(['part_number' => 'QT-100', 'name' => 'Bearing QT-100', 'brand' => 'SKF', 'product_id' => $w->priced->id]);
});

it('accepts price-on-request and out-of-stock products and the largest legal request', function () {
    $w = $this->w;
    loginAs($w->a1);

    createQuote(['lines' => [['product_id' => $w->por->id, 'quantity' => 1], ['product_id' => $w->oos->id, 'quantity' => 9999]]] + ['customer_note' => str_repeat('n', 2000)])
        ->assertCreated();
    $lines = array_map(fn ($i) => ['description' => "Custom item {$i}", 'quantity' => 1], range(1, 50));
    createQuote(['lines' => $lines])->assertCreated()->assertJsonPath('data.line_count', 50);
    createQuote(['lines' => [['description' => str_repeat('d', 255), 'quantity' => 1]]])->assertCreated();

    expect(QuoteRequest::count())->toBe(3);
});

it('stores customer text verbatim as JSON text', function () {
    $text = ['<script>alert(1)</script>', '=SUM(A1)', "He said \"hi\" and 'bye'", 'Müller 軸受 😀'];
    $response = loginAs($this->w->a1)->postJson('/api/v1/quotes', [
        'customer_note' => implode("\n", $text),
        'lines' => array_map(fn ($t) => ['description' => $t, 'quantity' => 1], $text),
    ])->assertCreated();

    expect(array_column($response->json('data.items'), 'name'))->toBe($text)->and($response->json('data.customer_note'))->toBe(implode("\n", $text));
});

it('ignores every field the customer must not set', function () {
    $w = $this->w;
    $response = loginAs($w->a1)->postJson('/api/v1/quotes', [
        'status' => 'accepted', 'company_id' => $w->b->id, 'created_by_user_id' => $w->b1->id, 'created_by' => $w->b1->id, 'quote_number' => 'QUO-AAAAA-AAAAA',
        'number' => 'QUO-AAAAA-AAAAA', 'currency' => 'EUR', 'internal_note' => 'HACK-INTERNAL', 'staff_note' => 'HACK-STAFF', 'valid_until' => '2030-01-01',
        'revision' => 9, 'sent_at' => '2026-01-01T00:00:00Z', 'subtotal_cents' => 1, 'tax_cents' => 5, 'shipping_cents' => 5, 'total_cents' => 1, 'unit_price_cents' => 1,
        'line_total_cents' => 1, 'id' => 999, 'created_at' => '2000-01-01T00:00:00Z',
        'lines' => [
            ['product_id' => $w->priced->id, 'quantity' => 2, 'unit_price_cents' => 1, 'line_total_cents' => 2, 'name' => 'HACK', 'brand' => 'HACK', 'part_number' => 'HACK', 'position' => 7],
            ['description' => 'Custom', 'quantity' => 1, 'unit_price_cents' => 1, 'brand' => 'HACK', 'product_id' => null],
        ],
    ])->assertCreated();

    $quote = QuoteRequest::firstOrFail();
    expect($quote->status->value)->toBe('submitted')->and($quote->company_id)->toBe($w->a->id)->and($quote->created_by_user_id)->toBe($w->a1->id)
        ->and($quote->quote_number)->not->toBe('QUO-AAAAA-AAAAA')->and($quote->currency)->toBe('USD')
        ->and($quote->internal_note)->toBeNull()->and($quote->staff_note)->toBeNull()->and($quote->valid_until)->toBeNull()
        ->and($quote->revision)->toBe(0)->and($quote->sent_at)->toBeNull()->and($quote->total_cents)->toBeNull()
        ->and($quote->tax_cents)->toBe(0)->and($quote->shipping_cents)->toBe(0)
        ->and($quote->id)->not->toBe(999)->and($quote->created_at->year)->not->toBe(2000);

    $items = QuoteItem::orderBy('position')->get();
    expect($items->pluck('name')->all())->toBe(['Bearing QT-100', 'Custom'])->and($items->pluck('position')->all())->toBe([1, 2])
        ->and($items->pluck('unit_price_cents')->all())->toBe([null, null])->and($items->pluck('brand')->all())->toBe(['SKF', null]);
    expect($response->getContent())->not->toContain('HACK');
});

it('forbids staff, admin and a customer without a company from creating quotes', function () {
    $body = ['lines' => [['description' => 'x', 'quantity' => 1]]];

    loginAs($this->w->staff)->postJson('/api/v1/quotes', $body)->assertForbidden();
    loginAs($this->w->admin)->postJson('/api/v1/quotes', $body)->assertForbidden();
    loginAs($this->w->staff)->postJson('/api/v1/quotes', ['lines' => 'garbage'])->assertForbidden();

    $orphan = makeUser('customer');
    $orphan->forceFill(['company_id' => null])->save();
    loginAs($orphan)->postJson('/api/v1/quotes', $body)->assertForbidden();
    loginAs($orphan)->getJson('/api/v1/quotes')->assertOk()->assertJsonPath('meta.total', 0);

    expect(QuoteRequest::count())->toBe(0);
});

it('returns 401 for guests', function () {
    $this->postJson('/api/v1/quotes', ['lines' => [['description' => 'x', 'quantity' => 1]]])->assertUnauthorized();
    $this->getJson('/api/v1/quotes')->assertUnauthorized();
    expect(QuoteRequest::count())->toBe(0);
});

it('queues one email to the staff recipients when a quote is created', function () {
    loginAs($this->w->a1)->postJson('/api/v1/quotes', ['lines' => [['description' => 'x', 'quantity' => 1]]])->assertCreated();

    // Quote created by a1: one message each for the staff user and the admin user of the fixture.
    Mail::assertQueued(QuoteSubmittedMail::class, 2);
});

it('rejects invalid create bodies with 422 on the right key and writes nothing', function (callable $body, string $key) {
    loginAs($this->w->a1);
    $response = createQuote($body($this->w));
    expect($response->status())->toBe(422, "expected 422 for {$key}: ".json_encode($body($this->w)));

    expect(array_keys($response->json('errors')))->toContain($key)
        ->and(QuoteRequest::count())->toBe(0)->and(QuoteItem::count())->toBe(0)->and(QuoteStatusHistory::count())->toBe(0);
    Mail::assertNothingQueued();
})->with([
    'lines missing' => [fn () => [], 'lines'],
    'lines empty' => [fn () => ['lines' => []], 'lines'],
    'lines null' => [fn () => ['lines' => null], 'lines'],
    'lines a string' => [fn () => ['lines' => 'x'], 'lines'],
    'lines an object' => [fn () => ['lines' => ['a' => ['description' => 'x', 'quantity' => 1]]], 'lines'],
    '51 lines' => [fn () => ['lines' => array_map(fn ($i) => ['description' => "d{$i}", 'quantity' => 1], range(1, 51))], 'lines'],
    'line is a number' => [fn () => ['lines' => [5]], 'lines'],
    'line is a string' => [fn () => ['lines' => ['x']], 'lines'],
    'line is null' => [fn () => ['lines' => [null]], 'lines'],
    'both product and description' => [fn ($w) => ['lines' => [['product_id' => $w->priced->id, 'description' => 'x', 'quantity' => 1]]], 'lines.0.description'],
    'neither product nor description' => [fn () => ['lines' => [['quantity' => 1]]], 'lines.0.product_id'],
    'description null' => [fn () => ['lines' => [['description' => null, 'quantity' => 1]]], 'lines.0.description'],
    'unknown product' => [fn () => ['lines' => [['product_id' => 99999999, 'quantity' => 1]]], 'lines.0.product_id'],
    'inactive product' => [fn ($w) => ['lines' => [['product_id' => $w->inactive->id, 'quantity' => 1]]], 'lines.0.product_id'],
    'product id string' => [fn ($w) => ['lines' => [['product_id' => (string) $w->priced->id, 'quantity' => 1]]], 'lines.0.product_id'],
    'product id zero' => [fn () => ['lines' => [['product_id' => 0, 'quantity' => 1]]], 'lines.0.product_id'],
    'product id negative' => [fn () => ['lines' => [['product_id' => -1, 'quantity' => 1]]], 'lines.0.product_id'],
    'product id float' => [fn () => ['lines' => [['product_id' => 1.5, 'quantity' => 1]]], 'lines.0.product_id'],
    'product id array' => [fn () => ['lines' => [['product_id' => [1], 'quantity' => 1]]], 'lines.0.product_id'],
    'duplicate product' => [fn ($w) => ['lines' => [['product_id' => $w->priced->id, 'quantity' => 1], ['product_id' => $w->priced->id, 'quantity' => 2]]], 'lines.1.product_id'],
    'description empty' => [fn () => ['lines' => [['description' => '', 'quantity' => 1]]], 'lines.0.description'],
    'description blank' => [fn () => ['lines' => [['description' => "  \t ", 'quantity' => 1]]], 'lines.0.description'],
    'description 256 chars' => [fn () => ['lines' => [['description' => str_repeat('d', 256), 'quantity' => 1]]], 'lines.0.description'],
    'description array' => [fn () => ['lines' => [['description' => ['x'], 'quantity' => 1]]], 'lines.0.description'],
    'description number' => [fn () => ['lines' => [['description' => 5, 'quantity' => 1]]], 'lines.0.description'],
    'description with newline' => [fn () => ['lines' => [['description' => "a\nb", 'quantity' => 1]]], 'lines.0.description'],
    'description with tab' => [fn () => ['lines' => [['description' => "a\tb", 'quantity' => 1]]], 'lines.0.description'],
    'description with interior NUL' => [fn () => ['lines' => [['description' => "a\0b", 'quantity' => 1]]], 'lines.0.description'],
    'description with ESC' => [fn () => ['lines' => [['description' => "a\x1Bb", 'quantity' => 1]]], 'lines.0.description'],
    'description with DEL' => [fn () => ['lines' => [['description' => "a\x7Fb", 'quantity' => 1]]], 'lines.0.description'],
    'description with NEL' => [fn () => ['lines' => [['description' => "a\u{0085}b", 'quantity' => 1]]], 'lines.0.description'],
    'description with zero width space' => [fn () => ['lines' => [['description' => "a\u{200B}b", 'quantity' => 1]]], 'lines.0.description'],
    'description with bidi override' => [fn () => ['lines' => [['description' => "a\u{202E}b", 'quantity' => 1]]], 'lines.0.description'],
    'quantity zero' => [fn () => ['lines' => [['description' => 'x', 'quantity' => 0]]], 'lines.0.quantity'],
    'quantity negative' => [fn () => ['lines' => [['description' => 'x', 'quantity' => -1]]], 'lines.0.quantity'],
    'quantity 10000' => [fn () => ['lines' => [['description' => 'x', 'quantity' => 10000]]], 'lines.0.quantity'],
    'quantity float' => [fn () => ['lines' => [['description' => 'x', 'quantity' => 1.5]]], 'lines.0.quantity'],
    'quantity string' => [fn () => ['lines' => [['description' => 'x', 'quantity' => '2']]], 'lines.0.quantity'],
    'quantity boolean' => [fn () => ['lines' => [['description' => 'x', 'quantity' => true]]], 'lines.0.quantity'],
    'quantity null' => [fn () => ['lines' => [['description' => 'x', 'quantity' => null]]], 'lines.0.quantity'],
    'quantity missing' => [fn () => ['lines' => [['description' => 'x']]], 'lines.0.quantity'],
    'error on the second line' => [fn () => ['lines' => [['description' => 'ok', 'quantity' => 1], ['description' => 'x', 'quantity' => 0]]], 'lines.1.quantity'],
    'po reference leading dash' => [fn () => ['po_reference' => '-ABC', 'lines' => [['description' => 'x', 'quantity' => 1]]], 'po_reference'],
    'po reference 65 chars' => [fn () => ['po_reference' => str_repeat('A', 65), 'lines' => [['description' => 'x', 'quantity' => 1]]], 'po_reference'],
    'po reference with NUL' => [fn () => ['po_reference' => "a\0b", 'lines' => [['description' => 'x', 'quantity' => 1]]], 'po_reference'],
    'po reference trailing NUL' => [fn () => ['po_reference' => "AB\0", 'lines' => [['description' => 'x', 'quantity' => 1]]], 'po_reference'],
    'po reference zero width space' => [fn () => ['po_reference' => "AB\u{200B}", 'lines' => [['description' => 'x', 'quantity' => 1]]], 'po_reference'],
    'po reference percent' => [fn () => ['po_reference' => 'a%b', 'lines' => [['description' => 'x', 'quantity' => 1]]], 'po_reference'],
    'po reference array' => [fn () => ['po_reference' => ['A'], 'lines' => [['description' => 'x', 'quantity' => 1]]], 'po_reference'],
    'customer note 2001 chars' => [fn () => ['customer_note' => str_repeat('n', 2001), 'lines' => [['description' => 'x', 'quantity' => 1]]], 'customer_note'],
    'customer note interior NUL' => [fn () => ['customer_note' => "a\0b", 'lines' => [['description' => 'x', 'quantity' => 1]]], 'customer_note'],
    'customer note ESC' => [fn () => ['customer_note' => "a\x1Bb", 'lines' => [['description' => 'x', 'quantity' => 1]]], 'customer_note'],
    'customer note zero width space' => [fn () => ['customer_note' => "a\u{200B}b", 'lines' => [['description' => 'x', 'quantity' => 1]]], 'customer_note'],
    'customer note array' => [fn () => ['customer_note' => ['x'], 'lines' => [['description' => 'x', 'quantity' => 1]]], 'customer_note'],
]);

it('normalises the optional text fields like the order PO reference', function () {
    $response = loginAs($this->w->a1)->postJson('/api/v1/quotes', [
        'po_reference' => '   ', 'customer_note' => "  note with trailing NUL and spaces \0 ",
        'lines' => [['description' => "  padded \0", 'quantity' => 1]],
    ])->assertCreated();

    expect($response->json('data.po_reference'))->toBeNull()->and($response->json('data.customer_note'))->toBe('note with trailing NUL and spaces')
        ->and($response->json('data.items.0.name'))->toBe('padded');

    createQuote(['customer_note' => '   ', 'lines' => [['description' => 'x', 'quantity' => 1]]])->assertCreated()->assertJsonPath('data.customer_note', null);
    createQuote(['customer_note' => "line1\r\nline2\ttabbed", 'lines' => [['description' => 'x', 'quantity' => 1]]])->assertCreated();
});

it('rejects invalid UTF-8 in form encoded text with 422, never 500', function (array $body, string $key) {
    loginAs($this->w->a1);
    $this->call('POST', '/api/v1/quotes', $body, [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:5173'])
        ->assertStatus(422)->assertJsonValidationErrors($key);

    expect(QuoteRequest::count())->toBe(0);
})->with([
    'description' => [['lines' => [['description' => "ab\xFFc", 'quantity' => 1]]], 'lines.0.description'],
    'note' => [['customer_note' => "ab\xFFc", 'lines' => [['description' => 'x', 'quantity' => 1]]], 'customer_note'],
    'po reference' => [['po_reference' => "ab\xFFc", 'lines' => [['description' => 'x', 'quantity' => 1]]], 'po_reference'],
]);

it('has an empty $fillable on the four quote models', function (string $class) {
    $model = new $class;

    expect($model->getFillable())->toBe([])
        ->and(fn () => $model->fill(['company_id' => 5, 'status' => 'accepted', 'quantity' => 3]))->toThrow(MassAssignmentException::class);
})->with([QuoteRequest::class, QuoteItem::class, QuoteStatusHistory::class, QuoteAttachment::class]);

it('uses the number generator and retries on a collision', function () {
    $existing = QuoteRequest::factory()->forCompany($this->w->a, $this->w->a1)->create();
    $fake = new class($existing->quote_number) extends QuoteNumber
    {
        private int $calls = 0;

        public function __construct(private string $taken) {}

        public function generate(): string
        {
            return ++$this->calls <= 2 ? $this->taken : parent::generate();
        }
    };
    app()->instance(QuoteNumber::class, $fake);

    $number = loginAs($this->w->a1)->postJson('/api/v1/quotes', ['lines' => [['description' => 'x', 'quantity' => 1]]])->assertCreated()->json('data.number');

    expect($number)->not->toBe($existing->quote_number)->and(QuoteRequest::count())->toBe(2);
});

it('generates unique quote numbers in the documented format', function () {
    $generator = new QuoteNumber;
    $numbers = array_map(fn () => $generator->generate(), range(1, 2000));

    expect(count(array_unique($numbers)))->toBe(2000)
        ->and(preg_grep('/^QUO-[A-HJKMNP-TV-Z2-9]{5}-[A-HJKMNP-TV-Z2-9]{5}$/D', $numbers))->toHaveCount(2000);
});
