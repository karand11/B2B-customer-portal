<?php

use App\Mail\QuoteSubmittedMail;
use App\Models\Order;
use App\Models\QuoteAttachment;
use App\Models\QuoteRequest;
use App\Policies\QuotePolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Mail::fake();
    Storage::fake('quotes');
    $this->travelTo(Carbon::parse('2026-11-25 12:00:00', 'UTC'));
    $this->w = quoteWorld();
});

afterEach(fn () => $this->travelBack());

/** SQL statements run by $fn. */
function sqlOf(Closure $fn): array
{
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });
    $fn();

    return $sql;
}

// ---------------------------------------------------------------- 1. company storage cap

it('caps the attachment bytes of a whole company: just under and exactly at the cap pass, one byte more fails', function () {
    $w = $this->w;
    $size = strlen(pdfBytes());
    config(['quotes.attachments.max_company_bytes' => 1000]);
    // A CLOSED quote of the company already holds 1000 - size bytes: closed quotes still count.
    $closed = makeQuote($w, 'declined');
    QuoteAttachment::factory()->create(['quote_request_id' => $closed->id, 'size_bytes' => 1000 - $size]);
    $open = makeQuote($w, 'submitted');

    loginAs($w->a1);
    $over = uploadTo($open->quote_number, fakeUpload('over.pdf', pdfOfSize($size + 1)))->assertStatus(422)->assertJsonValidationErrors('file');
    expect(array_keys($over->json('errors')))->toBe(['file'])->and($over->json('errors.file.0'))->toContain('storage limit');
    expect(QuoteAttachment::count())->toBe(1);

    uploadTo($open->quote_number, fakeUpload('exact.pdf', pdfBytes()))->assertCreated();
    uploadTo($open->quote_number, fakeUpload('more.pdf', pdfBytes()))->assertStatus(422)->assertJsonValidationErrors('file');
    expect(QuoteAttachment::sum('size_bytes'))->toEqual(1000);

    // The files of the closed quote cannot be freed any more.
    $file = $closed->attachments()->first();
    $this->deleteJson("/api/v1/quotes/{$closed->quote_number}/attachments/{$file->public_id}")->assertStatus(422)->assertJsonValidationErrors('status');
    expect(QuoteAttachment::count())->toBe(2);
});

it('counts across the quotes of one company, staff uploads included, and leaves other companies alone', function () {
    $w = $this->w;
    $size = strlen(pdfBytes());
    config(['quotes.attachments.max_company_bytes' => 2 * $size]);
    $first = makeQuote($w, 'submitted');
    $second = makeQuote($w, 'submitted');
    $foreign = QuoteRequest::factory()->forCompany($w->b, $w->b1)->create();

    loginAs($w->a1);
    uploadTo($first->quote_number, fakeUpload('a.pdf', pdfBytes()))->assertCreated();
    loginAs($w->a2);
    uploadTo($second->quote_number, fakeUpload('b.pdf', pdfBytes()))->assertCreated();

    // The cap is reached through two different quotes: a third file on either quote fails for customers and for staff.
    foreach ([$w->a1, $w->a2, $w->staff, $w->admin] as $user) {
        loginAs($user);
        uploadTo($first->quote_number, fakeUpload('c.pdf', pdfBytes()))->assertStatus(422)->assertJsonValidationErrors('file');
        uploadTo($second->quote_number, fakeUpload('c.pdf', pdfBytes()))->assertStatus(422)->assertJsonValidationErrors('file');
    }

    // Another company has its own quota.
    loginAs($w->b1);
    uploadTo($foreign->quote_number, fakeUpload('x.pdf', pdfBytes()))->assertCreated();
    uploadTo($foreign->quote_number, fakeUpload('y.pdf', pdfBytes()))->assertCreated();
    expect(QuoteAttachment::count())->toBe(4);
});

it('frees quota when an attachment is deleted from an open quote', function () {
    $w = $this->w;
    $size = strlen(pdfBytes());
    config(['quotes.attachments.max_company_bytes' => $size]);
    $quote = makeQuote($w, 'submitted');
    loginAs($w->a1);

    $id = uploadTo($quote->quote_number, fakeUpload('a.pdf', pdfBytes()))->assertCreated()->json('data.id');
    uploadTo($quote->quote_number, fakeUpload('b.pdf', pdfBytes()))->assertStatus(422);

    $this->deleteJson("/api/v1/quotes/{$quote->quote_number}/attachments/{$id}")->assertNoContent();
    uploadTo($quote->quote_number, fakeUpload('b.pdf', pdfBytes()))->assertCreated();
});

it('has a 500 MiB default company cap and takes the company lock before counting (parallel uploads serialise)', function () {
    expect(config('quotes.attachments.max_company_bytes'))->toBe(500 * 1024 * 1024)->and(config('quotes.max_open_per_company'))->toBe(50)
        ->and(config('quotes.uploads_per_hour'))->toBe(120);

    $quote = makeQuote($this->w, 'submitted');
    loginAs($this->w->a1);
    $sql = sqlOf(fn () => uploadTo($quote->quote_number, fakeUpload('a.pdf', pdfBytes()))->assertCreated());

    $lock = collect($sql)->search(fn ($q) => str_contains($q, 'from "companies"') && str_contains($q, 'for update'));
    $sum = collect($sql)->search(fn ($q) => str_contains($q, 'sum("quote_attachments"."size_bytes")'));
    expect($lock)->not->toBeFalse()->and($sum)->not->toBeFalse()->and($lock)->toBeLessThan($sum);
});

// ---------------------------------------------------------------- open quotes cap

it('caps the open quotes of a company at 422 on quote; closed and effectively expired quotes do not count', function () {
    $w = $this->w;
    config(['quotes.max_open_per_company' => 3, 'quotes.notify_address' => 'sales@portal.test']);
    makeQuote($w, 'declined');
    makeQuote($w, 'accepted');
    makeQuote($w, 'expired');
    makeQuote($w, 'quoted', ['valid_until' => '2026-11-01']); // stored quoted, but past its valid day: effectively expired
    makeQuote($w, 'submitted');
    makeQuote($w, 'inReview');
    $body = ['lines' => [['description' => 'x', 'quantity' => 1]]];

    loginAs($w->a1);
    $this->postJson('/api/v1/quotes', $body)->assertCreated(); // the 3rd open quote
    $before = [QuoteRequest::count(), DB::table('quote_items')->count()];

    $rejected = $this->postJson('/api/v1/quotes', $body)->assertStatus(422)->assertJsonValidationErrors('quote');
    expect(array_keys($rejected->json('errors')))->toBe(['quote'])->and([QuoteRequest::count(), DB::table('quote_items')->count()])->toBe($before);
    Mail::assertQueued(QuoteSubmittedMail::class, 1); // only the accepted create sent a mail

    // A colleague counts against the same company; another company is unaffected.
    loginAs($w->a2)->postJson('/api/v1/quotes', $body)->assertStatus(422)->assertJsonValidationErrors('quote');
    loginAs($w->b1)->postJson('/api/v1/quotes', $body)->assertCreated();

    // Withdrawing frees a slot (a quoted quote that is still valid counts as open too).
    $open = QuoteRequest::where('company_id', $w->a->id)->where('status', 'submitted')->first();
    loginAs($w->a1)->postJson("/api/v1/quotes/{$open->quote_number}/decline")->assertOk();
    $this->postJson('/api/v1/quotes', $body)->assertCreated();
    $this->postJson('/api/v1/quotes', $body)->assertStatus(422)->assertJsonValidationErrors('quote');
});

it('counts a still valid quoted quote as open and takes the company lock first (parallel creates serialise)', function () {
    $w = $this->w;
    config(['quotes.max_open_per_company' => 1]);
    makeQuote($w, 'quoted', ['valid_until' => '2026-11-25']); // valid through today, inclusive
    loginAs($w->a1);

    $sql = sqlOf(fn () => $this->postJson('/api/v1/quotes', ['lines' => [['description' => 'x', 'quantity' => 1]]])->assertStatus(422)->assertJsonValidationErrors('quote'));

    expect(collect($sql)->contains(fn ($q) => str_contains($q, 'from "companies"') && str_contains($q, 'for update')))->toBeTrue();
});

// ---------------------------------------------------------------- hourly upload limiter

it('limits uploads to the configured number per hour per user on top of the per-minute limit', function () {
    $w = $this->w;
    config(['quotes.uploads_per_hour' => 25]);
    $quote = makeQuote($w, 'submitted');
    $post = fn () => $this->post("/api/v1/quotes/{$quote->quote_number}/attachments", []);

    loginAs($w->a1);
    foreach (range(1, 20) as $i) {
        $post()->assertStatus(422);
    }
    $post()->assertStatus(429); // the per-minute limiter
    $this->travel(61)->seconds();
    foreach (range(1, 5) as $i) {
        $post()->assertStatus(422);
    }

    $limited = $post()->assertStatus(429)->assertHeader('Retry-After');
    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(60)->and($limited->json('message'))->toStartWith('Too many attempts.');

    // Another user is unaffected; the hour resets the counter.
    loginAs($w->a2);
    uploadTo($quote->quote_number, fakeUpload('a.pdf', pdfBytes()))->assertCreated();
    $this->travel(3601)->seconds();
    loginAs($w->a1);
    uploadTo($quote->quote_number, fakeUpload('b.pdf', pdfBytes()))->assertCreated();
});

// ---------------------------------------------------------------- customer-visible updated_at

it('does not let staff drafting reveal itself through the customers updated_at', function () {
    $w = $this->w;
    $quote = makeQuote($w, 'inReview', ['valid_until' => '2026-12-31']);
    $n = $quote->quote_number;
    $customerView = fn () => [
        loginAs($w->a1)->getJson("/api/v1/quotes/{$n}")->json('data.updated_at'),
        $this->getJson('/api/v1/quotes')->json('data.0.updated_at'),
    ];

    // Created 12:00, taken into review 13:00 (the last visible change).
    expect($customerView())->toBe(['2026-11-25T13:00:00Z', '2026-11-25T13:00:00Z']);
    expect(loginAs($w->staff)->getJson("/api/v1/quotes/{$n}")->json('data.updated_at'))->toBe('2026-11-25T12:00:00Z');

    $this->travelTo(Carbon::parse('2026-11-26 09:00:00', 'UTC'));
    loginAs($w->staff)->putJson("/api/v1/quotes/{$n}/pricing", ['lines' => [['position' => 1, 'unit_price_cents' => 500]]])->assertOk();
    $this->putJson("/api/v1/quotes/{$n}/internal-note", ['internal_note' => 'draft thoughts'])->assertOk();

    expect($customerView())->toBe(['2026-11-25T13:00:00Z', '2026-11-25T13:00:00Z']);
    expect(loginAs($w->staff)->getJson("/api/v1/quotes/{$n}")->json('data.updated_at'))->toBe('2026-11-26T09:00:00Z')
        ->and($this->getJson('/api/v1/quotes')->json('data.0.updated_at'))->toBe('2026-11-26T09:00:00Z');

    // A visible change (a status history row) moves it.
    $this->travelTo(Carbon::parse('2026-11-26 10:00:00', 'UTC'));
    loginAs($w->a1)->postJson("/api/v1/quotes/{$n}/decline")->assertOk();
    expect($customerView())->toBe(['2026-11-26T10:00:00Z', '2026-11-26T10:00:00Z']);
});

it('moves the customers updated_at on send and on reopen', function () {
    $w = $this->w;
    $quote = makeQuote($w, 'quoted');
    $n = $quote->quote_number;
    $at = fn () => loginAs($w->a1)->getJson("/api/v1/quotes/{$n}")->json('data.updated_at');
    $quotedAt = $at();
    expect($quotedAt)->toBe('2026-11-25T14:00:00Z'); // history: 12:00, 13:00, 14:00

    $this->travelTo(Carbon::parse('2026-11-27 08:00:00', 'UTC'));
    loginAs($w->staff)->postJson("/api/v1/quotes/{$n}/reopen")->assertOk();
    expect($at())->toBe('2026-11-27T08:00:00Z');
});

// ---------------------------------------------------------------- download headers

it('sends a locked-down Content-Security-Policy with the attachment download', function () {
    $quote = makeQuote($this->w, 'submitted');
    $file = QuoteAttachment::factory()->create(['quote_request_id' => $quote->id]);

    $response = loginAs($this->w->a1)->get("/api/v1/quotes/{$quote->quote_number}/attachments/{$file->public_id}")->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

// ---------------------------------------------------------------- revision is an integer

it('keeps revision an integer column so it cannot overflow after 32767 sends', function () {
    expect(DB::selectOne("select data_type from information_schema.columns where table_name = 'quote_requests' and column_name = 'revision'")->data_type)->toBe('integer');

    $w = $this->w;
    foreach ([32767, 40000] as $revision) {
        $quote = QuoteRequest::factory()->forCompany($w->a, $w->a1)->inReview()->withLines([[$w->priced, 1, 100]])
            ->create(['valid_until' => '2026-12-31', 'revision' => $revision]);

        loginAs($w->staff)->postJson("/api/v1/quotes/{$quote->quote_number}/send")->assertOk()->assertJsonPath('data.revision', $revision + 1);
        loginAs($w->a1)->postJson("/api/v1/quotes/{$quote->quote_number}/accept", ['revision' => $revision])->assertStatus(422)->assertJsonValidationErrors('revision');
        $this->postJson("/api/v1/quotes/{$quote->quote_number}/accept", ['revision' => $revision + 1])->assertOk();
    }
    expect(Order::count())->toBe(2);
});

it('accepts revisions up to the integer maximum and nothing above', function () {
    $quote = makeQuote($this->w, 'quoted', ['revision' => 2147483647]);
    loginAs($this->w->a1);

    $this->postJson("/api/v1/quotes/{$quote->quote_number}/accept", ['revision' => 2147483648])->assertStatus(422)->assertJsonValidationErrors('revision');
    $this->postJson("/api/v1/quotes/{$quote->quote_number}/accept", ['revision' => 2147483647])->assertOk();
});

// ---------------------------------------------------------------- policy: customer and staff roles together

it('never lets a user who also holds a staff role accept or decline', function () {
    $w = $this->w;
    $dual = makeUser('customer', $w->a);
    $dual->assignRole('staff');
    $own = makeQuote($w, 'quoted');
    $foreign = QuoteRequest::factory()->forCompany($w->b, $w->b1)->quoted()->create();

    foreach ([$own, $foreign] as $quote) {
        loginAs($dual);
        $this->postJson("/api/v1/quotes/{$quote->quote_number}/accept", ['revision' => 1])->assertForbidden();
        $this->postJson("/api/v1/quotes/{$quote->quote_number}/decline")->assertForbidden();
        expect($quote->fresh()->status->value)->toBe('quoted');
    }
    expect(Order::count())->toBe(0)
        ->and((new QuotePolicy)->accept($dual, $own)->allowed())->toBeFalse()->and((new QuotePolicy)->decline($dual, $foreign)->allowed())->toBeFalse()
        ->and((new QuotePolicy)->accept($dual, $own)->status())->toBeNull(); // a plain deny: 403, not the 404 of a hidden quote

    // Plain staff and admin keep the existing 403; plain customers are unchanged.
    foreach ([$w->staff, $w->admin] as $user) {
        loginAs($user)->postJson("/api/v1/quotes/{$own->quote_number}/accept", ['revision' => 1])->assertForbidden();
    }
    loginAs($w->b1)->postJson("/api/v1/quotes/{$own->quote_number}/accept", ['revision' => 1])->assertNotFound();
    loginAs($w->a2)->postJson("/api/v1/quotes/{$own->quote_number}/accept", ['revision' => 1])->assertOk();
});

// ---------------------------------------------------------------- malformed JSON

it('answers a malformed or non-object JSON body with 422 on body for every endpoint that takes a body', function (string $method, string $uri, string $who, string $raw) {
    $w = $this->w;
    $quote = makeQuote($w, 'inReview', ['valid_until' => '2026-12-31']);
    $uri = str_replace('{n}', $quote->quote_number, $uri);
    $before = [$quote->fresh()->only(['status', 'valid_until', 'internal_note', 'staff_note']), QuoteRequest::count(), Order::count()];

    $response = loginAs($w->{$who})->call($method, $uri, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:5173'], $raw);

    $response->assertStatus(422)->assertJsonValidationErrors('body');
    expect([$quote->fresh()->only(['status', 'valid_until', 'internal_note', 'staff_note']), QuoteRequest::count(), Order::count()])->toEqual($before);
})->with(function () {
    $endpoints = [
        'create' => ['POST', '/api/v1/quotes', 'a1'], 'pricing' => ['PUT', '/api/v1/quotes/{n}/pricing', 'staff'], 'send' => ['POST', '/api/v1/quotes/{n}/send', 'staff'],
        'reject' => ['POST', '/api/v1/quotes/{n}/reject', 'staff'], 'internal note' => ['PUT', '/api/v1/quotes/{n}/internal-note', 'staff'],
        'accept' => ['POST', '/api/v1/quotes/{n}/accept', 'a1'], 'decline' => ['POST', '/api/v1/quotes/{n}/decline', 'a1'],
    ];
    foreach ($endpoints as $name => $endpoint) {
        foreach (['truncated' => '{"lines": [', 'garbage' => 'not json', 'trailing comma' => '{"a":1,}', 'scalar' => '5', 'string' => '"x"', 'null' => 'null'] as $kind => $raw) {
            yield "{$name} / {$kind}" => [...$endpoint, $raw];
        }
    }
});

it('still accepts an empty body and a valid JSON object where all fields are optional', function () {
    $w = $this->w;
    $quote = makeQuote($w, 'inReview', ['valid_until' => '2026-12-31']);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:5173'];
    loginAs($w->staff);

    $this->call('PUT', "/api/v1/quotes/{$quote->quote_number}/pricing", [], [], [], $server, '')->assertOk();
    $this->call('PUT', "/api/v1/quotes/{$quote->quote_number}/pricing", [], [], [], $server, '{}')->assertOk();
    $this->call('PUT', "/api/v1/quotes/{$quote->quote_number}/pricing", [], [], [], $server, '[]')->assertOk();
});

it('keeps the role and routing checks ahead of body validation for malformed JSON', function () {
    $w = $this->w;
    $quote = makeQuote($w, 'inReview');
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:5173'];

    loginAs($w->a1)->call('PUT', "/api/v1/quotes/{$quote->quote_number}/pricing", [], [], [], $server, '{bad')->assertForbidden();
    loginAs($w->staff)->call('POST', "/api/v1/quotes/{$quote->quote_number}/accept", [], [], [], $server, '{bad')->assertForbidden();
    $this->call('POST', '/api/v1/quotes', [], [], [], $server, '{bad')->assertForbidden();
});
