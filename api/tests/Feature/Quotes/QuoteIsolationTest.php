<?php

use App\Models\QuoteAttachment;
use App\Models\QuoteRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-25 12:00:00', 'UTC'));
    Mail::fake();
    Storage::fake('quotes');
    $this->w = quoteWorld();
    $this->quote = makeQuote($this->w, 'quoted', ['po_reference' => 'SECRET-PO-77', 'customer_note' => 'secret note xyz']);
    $this->n = $this->quote->quote_number;
    $this->att = QuoteAttachment::factory()->create(['quote_request_id' => $this->quote->id]);
    $this->attUri = "/api/v1/quotes/{$this->n}/attachments/{$this->att->public_id}";
});

afterEach(fn () => $this->travelBack());

/** Every endpoint as [method, uri template with {n}, body, kind] where kind is customer, staff or shared. */
function quoteEndpoints(): array
{
    return [
        'show' => ['getJson', '/api/v1/quotes/{n}', [], 'shared'],
        'accept' => ['postJson', '/api/v1/quotes/{n}/accept', ['revision' => 1], 'customer'],
        'decline' => ['postJson', '/api/v1/quotes/{n}/decline', [], 'customer'],
        'review' => ['postJson', '/api/v1/quotes/{n}/review', [], 'staff'],
        'pricing' => ['putJson', '/api/v1/quotes/{n}/pricing', ['lines' => []], 'staff'],
        'send' => ['postJson', '/api/v1/quotes/{n}/send', [], 'staff'],
        'reject' => ['postJson', '/api/v1/quotes/{n}/reject', ['reason' => 'x'], 'staff'],
        'reopen' => ['postJson', '/api/v1/quotes/{n}/reopen', [], 'staff'],
        'internal-note' => ['putJson', '/api/v1/quotes/{n}/internal-note', ['internal_note' => 'x'], 'staff'],
        'attachment upload' => ['postJson', '/api/v1/quotes/{n}/attachments', [], 'shared'],
    ];
}

it('answers a foreign, an unknown and a malformed quote identically with the exact 404 body', function (string $name) {
    [$method, $uri, $body, $kind] = quoteEndpoints()[$name];
    $uri = fn (string $n) => str_replace('{n}', $n, $uri);
    // Who is "foreign" depends on the endpoint: customers on shared and customer endpoints, staff on staff endpoints for unknown numbers.
    $actor = $kind === 'staff' ? $this->w->staff : $this->w->b1;

    foreach ([true, false] as $debug) {
        config(['app.debug' => $debug]);
        loginAs($actor);
        $foreign = $kind === 'staff' ? 'QUO-AAAAA-AAAAA' : $this->n;
        $a = $this->{$method}($uri($foreign), $body)->assertNotFound();
        $b = $this->{$method}($uri('QUO-AAAAA-AAAAA'), $body)->assertNotFound();
        $c = $this->{$method}($uri('not*valid'), $body)->assertNotFound();

        expect($a->getContent())->toBe('{"message":"Not found."}')->and($b->getContent())->toBe($a->getContent())->and($c->getContent())->toBe($a->getContent());
    }
})->with(array_keys(quoteEndpoints()));

it('returns 401 for guests on every endpoint and 404 for a malformed number even for guests', function (string $name) {
    [$method, $uri, $body] = quoteEndpoints()[$name];

    $this->{$method}(str_replace('{n}', $this->n, $uri), $body)->assertUnauthorized();
    $this->{$method}(str_replace('{n}', 'bad!number', $uri), $body)->assertNotFound();
})->with(array_keys(quoteEndpoints()));

it('applies the same rules to the attachment download and delete', function () {
    $w = $this->w;
    $bad = "/api/v1/quotes/{$this->n}/attachments/NOT-A-UUID";
    $unknown = "/api/v1/quotes/{$this->n}/attachments/".'3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90';
    $upper = "/api/v1/quotes/{$this->n}/attachments/".strtoupper($this->att->public_id);

    // Guests: 401, but a malformed id is a plain 404 decided by routing.
    $this->getJson($this->attUri)->assertUnauthorized();
    $this->deleteJson($this->attUri)->assertUnauthorized();
    $this->getJson($bad)->assertNotFound();
    $this->getJson($upper)->assertNotFound();

    // Another company: 404 everywhere, with the exact body.
    loginAs($w->b1);
    foreach ([$this->attUri, $unknown, $bad, $upper] as $uri) {
        $this->getJson($uri)->assertNotFound()->assertExactJson(NOT_FOUND);
        $this->deleteJson($uri)->assertNotFound()->assertExactJson(NOT_FOUND);
    }

    // The owner company, an attachment id that belongs to ANOTHER quote: 404.
    $otherQuote = makeQuote($w, 'submitted');
    $otherAtt = QuoteAttachment::factory()->create(['quote_request_id' => $otherQuote->id]);
    loginAs($w->a1);
    $this->getJson("/api/v1/quotes/{$this->n}/attachments/{$otherAtt->public_id}")->assertNotFound()->assertExactJson(NOT_FOUND);
    $this->deleteJson("/api/v1/quotes/{$this->n}/attachments/{$otherAtt->public_id}")->assertNotFound()->assertExactJson(NOT_FOUND);
    $this->getJson("/api/v1/quotes/{$otherQuote->quote_number}/attachments/{$this->att->public_id}")->assertNotFound();

    // A customer of company B with an attachment of company A's quote through B's own quote number: 404.
    $bQuote = QuoteRequest::factory()->forCompany($w->b, $w->b1)->create();
    loginAs($w->b1)->getJson("/api/v1/quotes/{$bQuote->quote_number}/attachments/{$this->att->public_id}")->assertNotFound();

    loginAs($w->staff)->getJson($this->attUri)->assertOk();
    loginAs($w->admin)->getJson($this->attUri)->assertOk();
});

it('never leaks the quote of another company in lists, details or errors', function () {
    $w = $this->w;
    loginAs($w->b1);
    $own = QuoteRequest::factory()->forCompany($w->b, $w->b1)->create(['po_reference' => 'B-OWN-1']);

    $responses = [
        $this->getJson('/api/v1/quotes'),
        $this->getJson('/api/v1/quotes?q=SECRET-PO'),
        $this->getJson('/api/v1/quotes?q='.substr($this->n, 4, 5)),
        $this->getJson('/api/v1/quotes?company_id='.$w->a->id),
        $this->getJson('/api/v1/quotes?status=quoted&sort=number'),
        $this->getJson("/api/v1/quotes/{$this->n}"),
        $this->postJson("/api/v1/quotes/{$this->n}/accept", ['revision' => 1]),
        $this->postJson("/api/v1/quotes/{$this->n}/decline", ['reason' => 'x']),
        $this->postJson("/api/v1/quotes/{$this->n}/accept", ['revision' => 'bad']),
    ];

    foreach ($responses as $response) {
        expect($response->getContent())->not->toContain('SECRET-PO-77')->not->toContain('secret note')->not->toContain($this->n)->not->toContain('Northwind');
    }
    expect($responses[0]->json('meta.total'))->toBe(1)->and($responses[0]->json('data.0.number'))->toBe($own->quote_number)
        ->and($responses[1]->json('meta.total'))->toBe(0)->and($responses[3]->json('meta.total'))->toBe(1);
    expect($this->quote->fresh()->status->value)->toBe('quoted');
});

it('hides drafts, staff-only fields and staff files from customers until the quote is sent', function () {
    $w = $this->w;
    $draft = QuoteRequest::factory()->forCompany($w->a, $w->a1)->inReview()
        ->withLines([[$w->priced, 3, 987654], ['Secret custom line', 1, 123457]])
        ->create(['internal_note' => 'INTERNAL-SECRET-123', 'staff_note' => 'STAFF-NOTE-DRAFT', 'valid_until' => '2026-12-31']);
    QuoteAttachment::factory()->byStaff($w->staff)->named('formal-quotation.pdf')->create(['quote_request_id' => $draft->id, 'uploaded_by_user_id' => $w->staff->id]);
    QuoteAttachment::factory()->named('customer-drawing.pdf')->create(['quote_request_id' => $draft->id]);
    $n = $draft->quote_number;

    foreach ([$w->a1, $w->a2] as $customer) {
        $response = loginAs($customer)->getJson("/api/v1/quotes/{$n}")->assertOk();
        $data = $response->json('data');
        $raw = $response->getContent();

        expect($data)->not->toHaveKey('internal_note')
            ->and($data['subtotal_cents'])->toBeNull()->and($data['total_cents'])->toBeNull()->and($data['valid_until'])->toBeNull()
            ->and($data['expires_at'])->toBeNull()->and($data['staff_note'])->toBeNull()->and($data['revision'])->toBeNull()
            ->and(array_column($data['items'], 'unit_price_cents'))->toBe([null, null])->and(array_column($data['items'], 'line_total_cents'))->toBe([null, null])
            ->and(array_column($data['attachments'], 'name'))->toBe(['customer-drawing.pdf']);
        foreach (['INTERNAL-SECRET-123', 'STAFF-NOTE-DRAFT', '987654', '123457', 'formal-quotation', '2026-12-31', 'internal_note'] as $needle) {
            expect($raw)->not->toContain($needle);
        }
        $list = $this->getJson('/api/v1/quotes')->assertOk();
        $row = collect($list->json('data'))->firstWhere('number', $n);
        expect($row['total_cents'])->toBeNull()->and($row['valid_until'])->toBeNull()
            ->and($list->getContent())->not->toContain('987654')->not->toContain('INTERNAL');
    }

    $staff = loginAs($w->staff)->getJson("/api/v1/quotes/{$n}")->assertOk();
    expect($staff->json('data.internal_note'))->toBe('INTERNAL-SECRET-123')->and($staff->json('data.items.0.unit_price_cents'))->toBe(987654)
        ->and($staff->json('data.valid_until'))->toBe('2026-12-31')->and(array_column($staff->json('data.attachments'), 'name'))->toBe(['formal-quotation.pdf', 'customer-drawing.pdf'])
        ->and(array_keys($staff->json('data')))->toBe([...QUOTE_KEYS, 'customer_note', 'staff_note', 'internal_note', 'revision', 'expires_at', 'items', 'history', 'attachments', 'order', 'allowed_actions']);

    // Once sent, prices, the note and the staff file become visible; the internal note never does.
    loginAs($w->staff)->postJson("/api/v1/quotes/{$n}/send")->assertOk();
    $sent = loginAs($w->a1)->getJson("/api/v1/quotes/{$n}")->assertOk();
    expect($sent->json('data'))->not->toHaveKey('internal_note')->and($sent->json('data.items.0.unit_price_cents'))->toBe(987654)
        ->and($sent->json('data.staff_note'))->toBe('STAFF-NOTE-DRAFT')->and(array_column($sent->json('data.attachments'), 'name'))->toBe(['formal-quotation.pdf', 'customer-drawing.pdf'])
        ->and($sent->getContent())->not->toContain('INTERNAL-SECRET-123');
    // A staff name is not shown to customers.
    expect($sent->json('data.attachments.0.uploaded_by'))->toBe(['name' => null, 'role' => 'staff']);

    // Reopening hides everything again.
    loginAs($w->staff)->postJson("/api/v1/quotes/{$n}/reopen")->assertOk();
    $again = loginAs($w->a1)->getJson("/api/v1/quotes/{$n}")->assertOk();
    expect($again->json('data.items.0.unit_price_cents'))->toBeNull()->and($again->json('data.staff_note'))->toBeNull()
        ->and(array_column($again->json('data.attachments'), 'name'))->toBe(['customer-drawing.pdf'])->and($again->getContent())->not->toContain('987654');
});

it('never puts internal_note or draft data into a customer error response', function () {
    $draft = QuoteRequest::factory()->forCompany($this->w->a, $this->w->a1)->inReview()->create(['internal_note' => 'INTERNAL-SECRET-123']);

    $response = loginAs($this->w->a1)->postJson("/api/v1/quotes/{$draft->quote_number}/accept", ['revision' => 1])->assertStatus(422);
    expect($response->getContent())->not->toContain('INTERNAL')->not->toContain('internal_note');
    $this->postJson("/api/v1/quotes/{$draft->quote_number}/decline")->assertOk()->assertJsonMissingPath('data.internal_note');
});

it('keeps controllers on the company boundary (no unscoped quote query)', function () {
    $files = array_merge(glob(app_path('Http/Controllers/Api/V1/Quote*Controller.php')), glob(app_path('Http/Controllers/Api/V1/Concerns/*.php')));
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $code = file_get_contents($file);
        expect(preg_match('/QuoteRequest::(?!visibleTo\b|class\b)/', $code))->toBe(0, "{$file} queries QuoteRequest without visibleTo")
            ->and(preg_match('/QuoteAttachment::/', $code))->toBe(0, "{$file} queries QuoteAttachment directly")
            ->and(preg_match('/QuoteItem::|QuoteStatusHistory::/', $code))->toBe(0);
    }
    expect(file_get_contents(app_path('Http/Controllers/Api/V1/Concerns/FindsQuote.php')))->toContain('QuoteRequest::visibleTo($request->user())');
});
