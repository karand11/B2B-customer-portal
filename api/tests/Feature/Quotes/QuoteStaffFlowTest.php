<?php

use App\Mail\QuoteSentMail;
use App\Models\Order;
use App\Models\QuoteItem;
use App\Models\QuoteRequest;
use App\Models\QuoteStatusHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-11-25 12:00:00', 'UTC'));
    $this->w = quoteWorld();
});

afterEach(fn () => $this->travelBack());

function pricing(string $number, array $body)
{
    return test()->putJson("/api/v1/quotes/{$number}/pricing", $body);
}

it('lets staff review, price and send a quote, and queues exactly one email to the requester', function () {
    $w = $this->w;
    $number = loginAs($w->a1)->postJson('/api/v1/quotes', [
        'customer_note' => 'CUSTOMER-NOTE-XYZ',
        'lines' => [['product_id' => $w->priced->id, 'quantity' => 10], ['description' => 'LINE-TEXT-ABC taper roller', 'quantity' => 4]],
    ])->assertCreated()->json('data.number');
    Mail::assertNotQueued(QuoteSentMail::class);

    loginAs($w->staff)->postJson("/api/v1/quotes/{$number}/review")->assertOk()->assertJsonPath('data.status', 'in_review')
        ->assertJsonPath('data.allowed_actions', ['price', 'send', 'reject', 'attach', 'note']);

    $draft = pricing($number, ['lines' => [['position' => 1, 'unit_price_cents' => 1250], ['position' => 2, 'unit_price_cents' => 3875]], 'valid_until' => '2026-12-31'])->assertOk();
    expect($draft->json('data.items.0'))->toMatchArray(['unit_price_cents' => 1250, 'line_total_cents' => 12500])
        ->and($draft->json('data.items.1'))->toMatchArray(['unit_price_cents' => 3875, 'line_total_cents' => 15500])
        ->and($draft->json('data.subtotal_cents'))->toBe(28000)->and($draft->json('data.total_cents'))->toBe(28000)
        ->and($draft->json('data.valid_until'))->toBe('2026-12-31')->and($draft->json('data.revision'))->toBe(0)
        ->and(QuoteStatusHistory::count())->toBe(2);
    Mail::assertNotQueued(QuoteSentMail::class);

    // Still a draft for the customer.
    loginAs($w->a1)->getJson("/api/v1/quotes/{$number}")->assertOk()->assertJsonPath('data.total_cents', null)->assertJsonPath('data.items.0.unit_price_cents', null);

    $sent = loginAs($w->staff)->postJson("/api/v1/quotes/{$number}/send", ['staff_note' => 'Lead time 3 weeks', 'total_cents' => 1, 'status' => 'accepted'])->assertOk();
    expect($sent->json('data.status'))->toBe('quoted')->and($sent->json('data.revision'))->toBe(1)->and($sent->json('data.staff_note'))->toBe('Lead time 3 weeks')
        ->and($sent->json('data.expires_at'))->toBe('2027-01-01T00:00:00Z')->and($sent->json('data.allowed_actions'))->toBe(['reopen', 'note'])
        ->and($sent->json('data.total_cents'))->toBe(28000)->and($sent->json('data.history.2'))->toMatchArray(['from_status' => 'in_review', 'to_status' => 'quoted']);

    Mail::assertQueued(QuoteSentMail::class, 1);
    Mail::assertQueued(QuoteSentMail::class, function (QuoteSentMail $mail) use ($w, $number) {
        $html = $mail->render();

        return $mail->hasTo($w->a1->email) && ! $mail->hasTo($w->a2->email)
            && str_contains($html, config('app.web_url').'/quotes/'.$number)
            && str_contains($html, '280.00 USD') && str_contains($html, '2026-12-31')
            && ! str_contains($html, 'Lead time 3 weeks') && ! str_contains($html, 'CUSTOMER-NOTE-XYZ') && ! str_contains($html, 'LINE-TEXT-ABC')
            && $mail->envelope()->subject === "Your quote {$number} is ready";
    });

    // Now the customer sees the prices.
    $view = loginAs($w->a1)->getJson("/api/v1/quotes/{$number}")->assertOk();
    expect($view->json('data.total_cents'))->toBe(28000)->and($view->json('data.items.1.line_total_cents'))->toBe(15500)
        ->and($view->json('data.staff_note'))->toBe('Lead time 3 weeks')->and($view->json('data.revision'))->toBe(1)
        ->and($view->json('data.allowed_actions'))->toBe(['accept', 'decline']);
});

it('refuses to send an unpriced quote or one without a usable valid-until date', function () {
    $w = $this->w;
    $quote = makeQuote($w, 'inReview');
    $n = $quote->quote_number;
    loginAs($w->staff);

    $this->postJson("/api/v1/quotes/{$n}/send")->assertStatus(422)->assertJsonValidationErrors('lines');

    pricing($n, ['lines' => [['position' => 1, 'unit_price_cents' => 100]]])->assertOk()->assertJsonPath('data.total_cents', null);
    $this->postJson("/api/v1/quotes/{$n}/send")->assertStatus(422)->assertJsonValidationErrors('lines');

    pricing($n, ['lines' => [['position' => 2, 'unit_price_cents' => 100]]])->assertOk()->assertJsonPath('data.total_cents', 1400);
    $this->postJson("/api/v1/quotes/{$n}/send")->assertStatus(422)->assertJsonValidationErrors('valid_until');

    // A date that was fine when saved but is today by the time of sending is refused.
    pricing($n, ['valid_until' => '2026-11-26'])->assertOk();
    $this->travelTo(Carbon::parse('2026-11-26 08:00:00', 'UTC'));
    $this->postJson("/api/v1/quotes/{$n}/send")->assertStatus(422)->assertJsonValidationErrors('valid_until');
    $this->travelTo(Carbon::parse('2026-11-25 12:00:00', 'UTC'));

    expect($quote->fresh()->status->value)->toBe('in_review')->and($quote->fresh()->sent_at)->toBeNull();
    Mail::assertNothingQueued();

    pricing($n, ['valid_until' => '2026-11-26'])->assertOk();
    $this->postJson("/api/v1/quotes/{$n}/send")->assertOk();
    Mail::assertQueued(QuoteSentMail::class, 1);
});

it('validates the pricing body and the validity window', function (array $body, string $key) {
    $quote = makeQuote($this->w, 'inReview');
    $response = loginAs($this->w->staff)->putJson("/api/v1/quotes/{$quote->quote_number}/pricing", $body)->assertStatus(422);

    expect(array_keys($response->json('errors')))->toContain($key)->and(QuoteItem::whereNotNull('unit_price_cents')->count())->toBe(0)
        ->and($quote->fresh()->valid_until)->toBeNull();
})->with([
    'valid_until today' => [['valid_until' => '2026-11-25'], 'valid_until'],
    'valid_until yesterday' => [['valid_until' => '2026-11-24'], 'valid_until'],
    'valid_until 366 days ahead' => [['valid_until' => '2027-11-26'], 'valid_until'],
    'valid_until not a date' => [['valid_until' => 'tomorrow'], 'valid_until'],
    'valid_until impossible' => [['valid_until' => '2026-02-31'], 'valid_until'],
    'valid_until with time' => [['valid_until' => '2026-12-31T00:00:00Z'], 'valid_until'],
    'valid_until number' => [['valid_until' => 20261231], 'valid_until'],
    'valid_until array' => [['valid_until' => ['2026-12-31']], 'valid_until'],
    'lines not a list' => [['lines' => 'x'], 'lines'],
    'line not an object' => [['lines' => [5]], 'lines'],
    'more than 50 lines' => [['lines' => array_map(fn ($i) => ['position' => 1, 'unit_price_cents' => 1], range(1, 51))], 'lines'],
    'unknown position' => [['lines' => [['position' => 3, 'unit_price_cents' => 100]]], 'lines.0.position'],
    'position zero' => [['lines' => [['position' => 0, 'unit_price_cents' => 100]]], 'lines.0.position'],
    'position string' => [['lines' => [['position' => '1', 'unit_price_cents' => 100]]], 'lines.0.position'],
    'position missing' => [['lines' => [['unit_price_cents' => 100]]], 'lines.0.position'],
    'position twice' => [['lines' => [['position' => 1, 'unit_price_cents' => 100], ['position' => 1, 'unit_price_cents' => 200]]], 'lines.1.position'],
    'negative price' => [['lines' => [['position' => 1, 'unit_price_cents' => -1]]], 'lines.0.unit_price_cents'],
    'price above the cap' => [['lines' => [['position' => 1, 'unit_price_cents' => 1000000001]]], 'lines.0.unit_price_cents'],
    'price string' => [['lines' => [['position' => 1, 'unit_price_cents' => '12.50']]], 'lines.0.unit_price_cents'],
    'price float' => [['lines' => [['position' => 1, 'unit_price_cents' => 12.5]]], 'lines.0.unit_price_cents'],
    'price missing' => [['lines' => [['position' => 1]]], 'lines.0.unit_price_cents'],
]);

it('accepts the edges of the pricing rules and computes totals on the server', function () {
    $quote = makeQuote($this->w, 'inReview');
    $n = $quote->quote_number;
    loginAs($this->w->staff);

    pricing($n, ['valid_until' => '2026-11-26'])->assertOk()->assertJsonPath('data.valid_until', '2026-11-26');
    pricing($n, ['valid_until' => '2027-11-25'])->assertOk()->assertJsonPath('data.valid_until', '2027-11-25');
    pricing($n, ['valid_until' => null])->assertOk()->assertJsonPath('data.valid_until', null);
    pricing($n, [])->assertOk();

    // 1,000,000,000 x 10 and x 4 are exact in cents; the tampered total is ignored.
    $r = pricing($n, ['total_cents' => 1, 'subtotal_cents' => 1, 'lines' => [['position' => 1, 'unit_price_cents' => 1000000000], ['position' => 2, 'unit_price_cents' => 0, 'line_total_cents' => 5]]])->assertOk();
    expect($r->json('data.items.0.line_total_cents'))->toBe(10000000000)->and($r->json('data.items.1.line_total_cents'))->toBe(0)
        ->and($r->json('data.subtotal_cents'))->toBe(10000000000)->and($r->json('data.total_cents'))->toBe(10000000000);

    // Clearing one price clears the totals; lines that are not mentioned stay.
    $r = pricing($n, ['lines' => [['position' => 2, 'unit_price_cents' => null]]])->assertOk();
    expect($r->json('data.subtotal_cents'))->toBeNull()->and($r->json('data.total_cents'))->toBeNull()->and($r->json('data.items.0.unit_price_cents'))->toBe(1000000000);

    // Idempotent save.
    expect(QuoteStatusHistory::count())->toBe(2);
});

it('prices the largest possible quote exactly', function () {
    $w = $this->w;
    $quote = QuoteRequest::factory()->forCompany($w->a, $w->a1)->inReview()
        ->withLines(array_map(fn ($i) => ["Line {$i}", 9999, null], range(1, 50)))->create();

    $lines = array_map(fn ($i) => ['position' => $i, 'unit_price_cents' => 1000000000], range(1, 50));
    loginAs($w->staff);

    pricing($quote->quote_number, ['lines' => $lines])->assertOk()->assertJsonPath('data.total_cents', 50 * 9999 * 1000000000);
});

it('refuses pricing and sending once the quote is no longer in review', function (string $state) {
    $quote = makeQuote($this->w, $state);
    loginAs($this->w->staff);

    pricing($quote->quote_number, ['lines' => [['position' => 1, 'unit_price_cents' => 1]]])->assertStatus(422)->assertJsonValidationErrors('status');
    $this->postJson("/api/v1/quotes/{$quote->quote_number}/send")->assertStatus(422)->assertJsonValidationErrors('status');
    Mail::assertNothingQueued();
})->with(['submitted', 'quoted', 'accepted', 'declined', 'expired']);

it('rejects with a required reason that the customer can read', function () {
    $w = $this->w;
    $quote = makeQuote($w, 'inReview');
    $n = $quote->quote_number;
    loginAs($w->staff);

    foreach ([[], ['reason' => ''], ['reason' => '   '], ['reason' => str_repeat('r', 501)], ['reason' => "a\0b"], ['reason' => ['x']]] as $body) {
        $this->postJson("/api/v1/quotes/{$n}/reject", $body)->assertStatus(422)->assertJsonValidationErrors('reason');
    }
    expect($quote->fresh()->status->value)->toBe('in_review');

    $this->postJson("/api/v1/quotes/{$n}/reject", ['reason' => "We do not stock\nthis series."])->assertOk()->assertJsonPath('data.status', 'declined');
    $history = loginAs($w->a1)->getJson("/api/v1/quotes/{$n}")->json('data.history');
    expect(end($history))->toMatchArray(['from_status' => 'in_review', 'to_status' => 'declined', 'note' => "We do not stock\nthis series.", 'actor' => ['name' => null, 'role' => 'staff']]);
    Mail::assertNothingQueued();
});

it('reopens a quoted quote: hides prices again, keeps the draft and bumps the revision on re-send', function () {
    $w = $this->w;
    $quote = makeQuote($w, 'quoted');
    $n = $quote->quote_number;

    loginAs($w->staff)->postJson("/api/v1/quotes/{$n}/reopen")->assertOk()->assertJsonPath('data.status', 'in_review')->assertJsonPath('data.revision', 1);
    expect($quote->fresh()->sent_at)->toBeNull()->and($quote->fresh()->total_cents)->not->toBeNull();

    $customer = loginAs($w->a1)->getJson("/api/v1/quotes/{$n}")->assertOk();
    expect($customer->json('data.total_cents'))->toBeNull()->and($customer->json('data.revision'))->toBeNull()->and($customer->json('data.valid_until'))->toBeNull()
        ->and($customer->json('data.items.0.unit_price_cents'))->toBeNull()->and($customer->json('data.staff_note'))->toBeNull()
        ->and($customer->json('data.allowed_actions'))->toBe(['decline', 'attach']);
    $this->postJson("/api/v1/quotes/{$n}/accept", ['revision' => 1])->assertStatus(422)->assertJsonValidationErrors('status');

    loginAs($w->staff)->postJson("/api/v1/quotes/{$n}/send")->assertOk()->assertJsonPath('data.revision', 2);
    Mail::assertQueued(QuoteSentMail::class, 1);

    // The stale page (revision 1) can no longer accept; the fresh one can.
    loginAs($w->a1)->postJson("/api/v1/quotes/{$n}/accept", ['revision' => 1])->assertStatus(422)->assertJsonValidationErrors('revision');
    expect(Order::count())->toBe(0);
    $this->postJson("/api/v1/quotes/{$n}/accept", ['revision' => 2])->assertOk();
});

it('lets staff save an internal note in any status but never shows it to customers', function (string $state) {
    $w = $this->w;
    $quote = makeQuote($w, $state);
    $n = $quote->quote_number;

    loginAs($w->staff)->putJson("/api/v1/quotes/{$n}/internal-note", ['internal_note' => "Margin ok\nsupplier confirmed"])->assertOk()
        ->assertJsonPath('data.internal_note', "Margin ok\nsupplier confirmed")->assertJsonPath('data.status', $quote->fresh()->effectiveStatus()->value);
    loginAs($w->admin)->putJson("/api/v1/quotes/{$n}/internal-note", ['internal_note' => null])->assertOk()->assertJsonPath('data.internal_note', null);
    loginAs($w->admin)->putJson("/api/v1/quotes/{$n}/internal-note", ['internal_note' => 'SECRET-INTERNAL'])->assertOk();

    $raw = loginAs($w->a1)->getJson("/api/v1/quotes/{$n}")->assertOk();
    expect($raw->json('data'))->not->toHaveKey('internal_note')->and($raw->getContent())->not->toContain('SECRET-INTERNAL')->not->toContain('internal_note');
    expect(loginAs($w->a1)->getJson('/api/v1/quotes')->getContent())->not->toContain('internal_note')->not->toContain('SECRET-INTERNAL');
})->with(['submitted', 'inReview', 'quoted', 'accepted', 'declined', 'expired']);

it('validates the internal note and the staff note', function () {
    $quote = makeQuote($this->w, 'inReview');
    $n = $quote->quote_number;
    loginAs($this->w->staff);

    foreach ([[], ['internal_note' => str_repeat('n', 2001)], ['internal_note' => "a\0b"], ['internal_note' => ['x']], ['internal_note' => "a\x1Bb"]] as $body) {
        $this->putJson("/api/v1/quotes/{$n}/internal-note", $body)->assertStatus(422)->assertJsonValidationErrors('internal_note');
    }
    $this->putJson("/api/v1/quotes/{$n}/internal-note", ['internal_note' => str_repeat('n', 2000)])->assertOk();

    foreach ([['staff_note' => str_repeat('n', 2001)], ['staff_note' => "a\0b"], ['staff_note' => ['x']]] as $body) {
        $this->postJson("/api/v1/quotes/{$n}/send", $body)->assertStatus(422)->assertJsonValidationErrors('staff_note');
    }
});

it('keeps the existing staff note when send has no staff_note key and clears it with null', function () {
    $w = $this->w;
    $quote = makeQuote($w, 'inReview', ['staff_note' => 'Kept note', 'valid_until' => '2026-12-31']);
    QuoteItem::where('quote_request_id', $quote->id)->update(['unit_price_cents' => 100, 'line_total_cents' => DB::raw('100 * quantity')]);
    loginAs($w->staff)->postJson("/api/v1/quotes/{$quote->quote_number}/send")->assertOk()->assertJsonPath('data.staff_note', 'Kept note');

    loginAs($w->staff)->postJson("/api/v1/quotes/{$quote->quote_number}/reopen")->assertOk();
    $this->postJson("/api/v1/quotes/{$quote->quote_number}/send", ['staff_note' => null])->assertOk()->assertJsonPath('data.staff_note', null);
});
