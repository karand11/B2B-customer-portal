<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Services\InvoicePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->w = orderWorld();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'UTC'));
});

afterEach(fn () => $this->travelBack());

/** Number of pages: the /Count of the /Pages object is the last /Count in the file (the outline /Count 0 comes first). */
function pdfPageCount(string $pdf): int
{
    preg_match_all('#/Count (\d+)#', $pdf, $m);

    return (int) end($m[1]);
}

function pdfUrl(Invoice $invoice): string
{
    return "/api/v1/invoices/{$invoice->public_id}/pdf";
}

/** The rendered HTML the PDF is built from. */
function invoiceHtml(Invoice $invoice): string
{
    return app(InvoicePdf::class)->html(Invoice::with('items')->findOrFail($invoice->id));
}

// ---------------------------------------------------------------- endpoint and headers

it('returns a real PDF with the exact headers and a filename built only from the number', function () {
    $invoice = makeInvoice($this->w->a);

    $response = loginAs($this->w->a1)->get(pdfUrl($invoice))->assertOk();
    $body = $response->getContent();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toBe("attachment; filename=\"{$invoice->number}.pdf\"")
        ->and($response->headers->get('Content-Disposition'))->toMatch('/^attachment; filename="INV-\d{4}-\d{6,9}\.pdf"$/')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen($body))
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
        ->and(strlen($body))->toBeGreaterThan(2048)->toBeLessThan(5 * 1024 * 1024)
        ->and(substr($body, 0, 5))->toBe('%PDF-')->and(substr(rtrim($body), -5))->toBe('%%EOF')
        ->and(pdfPageCount($body))->toBe(1);
});

it('lets staff and admin download any company invoice and customers of the same company share it', function () {
    $invoice = makeInvoice($this->w->a);

    foreach (['staff', 'admin', 'a1', 'a2'] as $who) {
        $body = loginAs($this->w->{$who})->get(pdfUrl($invoice))->assertOk()->getContent();
        expect(substr($body, 0, 5))->toBe('%PDF-');
    }
});

it('answers a foreign or unknown invoice PDF with the identical 404 and a guest with 401', function () {
    $invoice = makeInvoice($this->w->a);

    loginAs($this->w->b1)->getJson(pdfUrl($invoice))->assertNotFound()->assertExactJson(NOT_FOUND);
    loginAs($this->w->b1)->getJson('/api/v1/invoices/'.Str::uuid().'/pdf')->assertNotFound()->assertExactJson(NOT_FOUND);
    loginAs($this->w->staff)->getJson('/api/v1/invoices/'.Str::uuid().'/pdf')->assertNotFound()->assertExactJson(NOT_FOUND);

    $this->app['auth']->forgetGuards();
    $this->getJson(pdfUrl($invoice))->assertUnauthorized();
});

it('answers a malformed invoice id with a plain 404 for every role, including an invoice number', function (string $id) {
    $invoice = makeInvoice($this->w->a);
    $id = str_replace('{number}', $invoice->number, $id);

    foreach (['a1', 'b1', 'staff', 'admin'] as $who) {
        loginAs($this->w->{$who})->getJson("/api/v1/invoices/{$id}/pdf")->assertNotFound();
    }
})->with(['{number}', 'abc', 'INV-2026-000001', '3F2B8C1E-4D5A-4B7E-9C10-2A6F5E8D7B90', '3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b9', '1']);

it('downloads a voided invoice with a VOID banner and never prints paid or overdue', function () {
    $void = makeInvoice($this->w->a, 'void');

    expect(loginAs($this->w->a1)->get(pdfUrl($void))->assertOk()->getContent())->toStartWith('%PDF-')
        ->and(invoiceHtml($void))->toContain('VOID - this invoice has been cancelled');

    foreach (['open', 'paid', 'overdue'] as $state) {
        $html = invoiceHtml(makeInvoice($this->w->a, $state));
        expect($html)->not->toContain('VOID')->and($html)->not->toMatch('/\b(paid|overdue)\b/i');
    }
});

it('renders the invoice figures and snapshots into the HTML', function () {
    config(['invoicing.vat_rate_bp' => 1900]);
    $invoice = Invoice::factory()->forOrder(orderWith($this->w->a, [[1250, 3], [333, 10], [99999, 1]], 'confirmed', ['po_reference' => 'PO-4711/A']))->create();
    $html = invoiceHtml($invoice);

    expect($html)->toContain($invoice->number)->toContain('2026-11-26')->toContain('2026-12-26')->toContain($invoice->order_number)->toContain('PO-4711/A')
        ->toContain('USD 1,070.79')->toContain('USD 203.45')->toContain('USD 1,274.24')->toContain('VAT 19%')
        ->toContain('Test Seller GmbH')->toContain($this->w->a->name)->toContain('Seller Street 1')->toContain('XX00 TEST IBAN');
});

it('omits the PO reference row when the order has none', function () {
    expect(invoiceHtml(makeInvoice($this->w->a)))->not->toContain('PO reference');
});

// ---------------------------------------------------------------- hostile strings

function hostileStrings(): array
{
    return [
        'script' => '<script>alert(1)</script>',
        'img' => '"><img src=x onerror=1>',
        'specials' => '& < > " \'',
        'blade' => '{{ 7*7 }} {!! x !!} @php echo 1; @endphp',
        'bidi' => "A\u{202E}B\u{200B}C\u{FEFF}D\u{2066}E",
        'emoji' => "Bolt \u{1F600} nut",
        'unicode' => 'Müller & Söhne GmbH, Zażółć gęślą jaźń, Привет Ωμέγα € ≥',
        'long' => str_repeat('W', 230),
    ];
}

/** An invoice whose company, address, product names, PO reference and seller config all carry the hostile string. */
function hostileInvoice(string $text): Invoice
{
    $company = Company::factory()->create(['name' => "Co {$text}", 'billing_address' => "Line one {$text}\nLine two\nLine three", 'tax_id' => 'T-1']);
    config([
        'invoicing.seller.name' => "Seller {$text}", 'invoicing.seller.address_lines' => ["Addr {$text}", 'Second'], 'invoicing.seller.footer' => "Foot {$text}",
        'invoicing.seller.payment_terms_text' => "Terms {$text}", 'invoicing.seller.bank.iban' => "IBAN {$text}",
    ]);
    $product = Product::factory()->create(['part_number' => 'HOST-'.substr(md5($text), 0, 6), 'name' => "Item {$text}", 'category_id' => category()->id]);
    $order = Order::factory()->confirmed()->forCompany($company)->withLines([[$product, 1]])->create(['po_reference' => 'PO-'.substr(preg_replace('/[^A-Za-z0-9]/', '', $text), 0, 8)]);

    return Invoice::factory()->forOrder($order)->create();
}

it('escapes and cleans every hostile string in the rendered HTML', function (string $kind) {
    $text = hostileStrings()[$kind];
    $html = invoiceHtml(hostileInvoice($text));
    $body = substr($html, strpos($html, '<body'));

    expect($html)->not->toContain('<script')->not->toContain('<img')->not->toContain('onerror=1>')
        ->and(preg_match('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\x{00AD}]/u', $html))->toBe(0)
        ->and($html)->not->toContain("\u{1F600}");

    // No unbroken run of more than 28 characters in the visible text.
    $words = preg_split('/\s+/u', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
    expect(max(array_map('mb_strlen', $words)))->toBeLessThanOrEqual(28);

    match ($kind) {
        'script' => expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;'),
        'img' => expect($html)->toContain('&quot;&gt;&lt;img src=x onerror=1&gt;'),
        'specials' => expect($html)->toContain('&amp; &lt; &gt; &quot; &#039;'),
        'blade' => expect($html)->toContain('{{ 7*7 }}')->toContain('{!! x !!}')->toContain('@php echo 1; @endphp')->not->toContain('>49<'),
        'emoji' => expect($html)->toContain("Bolt \u{FFFD} nut"),
        'unicode' => expect($html)->toContain('Müller &amp; Söhne GmbH')->toContain('Zażółć gęślą jaźń')->toContain('Привет Ωμέγα'),
        default => null,
    };
})->with(array_keys(hostileStrings()));

it('prints one block per address line', function () {
    $html = invoiceHtml(hostileInvoice('x'));

    expect($html)->toContain('<div>Line one x</div>')->toContain('<div>Line two</div>')->toContain('<div>Line three</div>')
        ->toContain('<div>Addr x</div>')->toContain('<div>Second</div>');
});

it('still renders a PDF for every hostile string', function (string $kind) {
    $invoice = hostileInvoice(hostileStrings()[$kind]);

    $response = loginAs($this->w->staff)->get(pdfUrl($invoice))->assertOk();
    $body = $response->getContent();

    expect(substr($body, 0, 5))->toBe('%PDF-')->and(pdfPageCount($body))->toBeGreaterThanOrEqual(1);
    foreach ($response->headers->all() as $name => $values) {
        expect(implode(' ', $values))->not->toContain('Müller')->not->toContain('<');
    }
})->with(array_keys(hostileStrings()));

it('never lets user text reach a header, even with a hostile company name', function () {
    $invoice = hostileInvoice('Müller & Söhne <GmbH> "Test"');

    $headers = loginAs($this->w->staff)->get(pdfUrl($invoice))->assertOk()->headers->all();

    expect(json_encode($headers, JSON_UNESCAPED_UNICODE))->not->toContain('Müller')->not->toContain('GmbH')->not->toContain($invoice->buyer['name'])->not->toContain($invoice->order_number);
});

it('keeps the Blade template free of raw output, PHP, images, links, scripts, imports, urls and http', function () {
    $template = file_get_contents(resource_path('views/invoices/pdf.blade.php'));

    foreach (['{!!', '@php', '<img', '<link', '<script', '@import', 'url(', 'http', '@include', '@extends'] as $forbidden) {
        expect($template)->not->toContain($forbidden);
    }
});

// ---------------------------------------------------------------- size, pages, performance

it('renders a 200-line invoice in under 30 s with bounded memory growth and several pages, and a 3-line invoice on one page', function () {
    // phpunit.xml raises memory_limit to 256M for the suite (the PDF route needs headroom, see docs): no ini games here.
    $big = Invoice::factory()->forCompany($this->w->a)->withLines(200)->create();
    $small = Invoice::factory()->forCompany($this->w->a)->withLines(3)->create();

    $memory = memory_get_peak_usage(true);
    $start = microtime(true);
    $body = loginAs($this->w->staff)->get(pdfUrl($big))->assertOk()->getContent();
    $seconds = microtime(true) - $start;

    expect($seconds)->toBeLessThan(30)->and(memory_get_peak_usage(true) - $memory)->toBeLessThan(200 * 1024 * 1024)
        ->and(substr($body, 0, 5))->toBe('%PDF-')->and(pdfPageCount($body))->toBeGreaterThanOrEqual(3)
        ->and(pdfPageCount(loginAs($this->w->staff)->get(pdfUrl($small))->getContent()))->toBe(1);
});

// ---------------------------------------------------------------- dependency sanity

it('resolves the dompdf facade with remote files, PHP and JavaScript disabled and a narrow chroot', function () {
    $options = app('dompdf')->getOptions();

    expect(Pdf::getFacadeRoot())->not->toBeNull()
        ->and(config('dompdf.options.enable_remote'))->toBeFalse()->and(config('dompdf.options.enable_php'))->toBeFalse()->and(config('dompdf.options.enable_javascript'))->toBeFalse()
        ->and($options->getIsRemoteEnabled())->toBeFalse()->and($options->getIsPhpEnabled())->toBeFalse()->and($options->getIsJavascriptEnabled())->toBeFalse()
        ->and(array_map('realpath', $options->getChroot()))->toBe([realpath(resource_path('pdf'))])
        ->and(config('dompdf.options.chroot'))->not->toBe(base_path())->and($options->getDefaultFont())->toBe('DejaVu Sans')
        ->and($options->getDefaultPaperSize())->toBe('a4')->and(config('dompdf.options.log_output_file'))->toBeNull()
        ->and(is_dir(storage_path('fonts')))->toBeTrue()->and(is_writable(storage_path('fonts')))->toBeTrue();
});

// ---------------------------------------------------------------- layout rules (checked on the rendered HTML, never on PDF text)

it('keeps the last four rows, the totals and the payment block together in one unsplittable cell', function (int $lines, int $inHead) {
    $invoice = Invoice::factory()->forCompany($this->w->a)->withLines($lines)->create();
    $html = invoiceHtml($invoice);
    [$before, $keep] = explode('class="keep"', $html, 2);

    expect(substr_count($before, 'Custom bearing line'))->toBe($inHead)->and(substr_count($keep, 'Custom bearing line'))->toBe($lines - $inHead)
        ->and($keep)->toContain('Subtotal')->toContain('class="payment"')->and($before)->not->toContain('Subtotal');
    // Nothing is lost: every line appears exactly once.
    foreach (range(1, $lines) as $i) {
        expect(substr_count($html, "Custom bearing line {$i}<"))->toBe(1);
    }
})->with([[1, 0], [3, 0], [4, 0], [5, 1], [24, 20], [31, 27], [100, 96]]);

it('renders a PDF for 1, 24 to 31 and 100 lines; a long invoice always spans more than one page', function () {
    foreach ([1 => [1, 1], 24 => [1, 3], 27 => [2, 3], 31 => [2, 3], 100 => [3, 8]] as $lines => [$min, $max]) {
        $invoice = Invoice::factory()->forCompany($this->w->a)->withLines($lines)->create();
        $body = loginAs($this->w->staff)->get(pdfUrl($invoice))->assertOk()->getContent();

        expect(substr($body, 0, 5))->toBe('%PDF-')->and(substr(rtrim($body), -5))->toBe('%%EOF')->and(pdfPageCount($body))->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max);
    }
});

it('confines the footer text to the left 72% so it cannot reach the page number, even at 300 characters', function () {
    config(['invoicing.seller.footer' => str_repeat('Registered office Berlin. ', 11).'END']);
    expect(mb_strlen(config('invoicing.seller.footer')))->toBeLessThanOrEqual(300);
    $invoice = Invoice::factory()->forCompany($this->w->a)->create();
    $html = invoiceHtml($invoice);

    expect($html)->toContain('.footer .text { width: 72%; }')->toContain('<div class="text">')->toContain('Registered office Berlin.')
        ->and(loginAs($this->w->staff)->get(pdfUrl($invoice))->assertOk()->getContent())->toStartWith('%PDF-');
});

it('gives the header meta table percentage widths and wraps a 64-character PO reference in its own cell', function () {
    $invoice = Invoice::factory()->forOrder(orderWith($this->w->a, [[1000, 1]], 'confirmed', ['po_reference' => str_repeat('A', 64)]))->create();
    $html = invoiceHtml($invoice);

    expect($html)->toContain('.meta .label { width: 38%;')->toContain('.meta .value { width: 62%;')
        ->and($html)->not->toContain('<colgroup')->not->toContain('<col ')->not->toContain('mm;"')
        ->and($html)->toMatch('~<td class="value">A{20} A{20} A{20} A{4}</td>~');
});
