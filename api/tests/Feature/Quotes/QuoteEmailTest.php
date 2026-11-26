<?php

use App\Mail\QuoteSentMail;
use App\Mail\QuoteSubmittedMail;
use App\Models\QuoteItem;
use App\Models\QuoteRequest;
use App\Models\QuoteStatusHistory;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-11-25 12:00:00', 'UTC'));
    $this->w = quoteWorld();
});

afterEach(fn () => $this->travelBack());

function submitQuote(?string $note = null)
{
    return test()->postJson('/api/v1/quotes', [
        'po_reference' => 'PO-4711/A', 'customer_note' => $note ?? 'CUSTOMER-NOTE-XYZ',
        'lines' => [['description' => 'LINE-TEXT-ABC taper roller', 'quantity' => 4]],
    ]);
}

it('queues QuoteSubmittedMail to staff and admin users (never customers) when no mailbox is configured', function () {
    $w = $this->w;
    config(['quotes.notify_address' => null]);
    $number = loginAs($w->a1)->postJson('/api/v1/quotes', ['po_reference' => 'PO-4711/A', 'customer_note' => 'CUSTOMER-NOTE-XYZ',
        'lines' => [['description' => 'LINE-TEXT-ABC taper roller', 'quantity' => 4], ['product_id' => $w->priced->id, 'quantity' => 1]]])->assertCreated()->json('data.number');

    // One message per recipient, each with exactly one address in To.
    Mail::assertQueued(QuoteSubmittedMail::class, 2);
    Mail::assertQueued(QuoteSubmittedMail::class, fn (QuoteSubmittedMail $mail) => $mail->hasTo($w->staff->email) && count($mail->to) === 1);
    Mail::assertQueued(QuoteSubmittedMail::class, fn (QuoteSubmittedMail $mail) => $mail->hasTo($w->admin->email) && count($mail->to) === 1);
    Mail::assertNotQueued(QuoteSubmittedMail::class, fn (QuoteSubmittedMail $mail) => $mail->hasTo($w->a1->email) || $mail->hasTo($w->a2->email) || $mail->hasTo($w->b1->email));
    Mail::assertQueued(QuoteSubmittedMail::class, function (QuoteSubmittedMail $mail) use ($w, $number) {
        $html = $mail->render();

        return str_contains($html, $number) && str_contains($html, config('app.web_url').'/quotes/'.$number)
            && str_contains($html, 'Northwind Test Ltd') && str_contains($html, $w->a1->name) && str_contains($html, 'PO-4711/A')
            && str_contains($html, '2') // line count
            && ! str_contains($html, 'CUSTOMER-NOTE-XYZ') && ! str_contains($html, 'LINE-TEXT-ABC') && ! str_contains($html, 'QT-100')
            && $mail->envelope()->subject === "New quote request {$number}";
    });
});

it('queues QuoteSubmittedMail to exactly the configured mailbox when one is set', function () {
    config(['quotes.notify_address' => 'sales@portal.test']);
    loginAs($this->w->a1);
    submitQuote()->assertCreated();

    Mail::assertQueued(QuoteSubmittedMail::class, fn (QuoteSubmittedMail $mail) => $mail->hasTo('sales@portal.test') && ! $mail->hasTo($this->w->staff->email) && count($mail->to) === 1);
});

it('shows "none" when the quote has no PO reference', function () {
    config(['quotes.notify_address' => 'sales@portal.test']);
    loginAs($this->w->a1)->postJson('/api/v1/quotes', ['lines' => [['description' => 'x', 'quantity' => 1]]])->assertCreated();

    Mail::assertQueued(QuoteSubmittedMail::class, fn (QuoteSubmittedMail $mail) => str_contains($mail->render(), 'PO reference: none'));
});

it('builds links from the configured web URL and ignores the request Host header', function () {
    config(['app.web_url' => 'https://portal.example.test']);
    $response = loginAs($this->w->a1)->withServerVariables(['HTTP_HOST' => 'evil.test', 'HTTP_X_FORWARDED_HOST' => 'evil.test'])
        ->withHeaders(['Host' => 'evil.test', 'X-Forwarded-Host' => 'evil.test'])
        ->postJson('/api/v1/quotes', ['lines' => [['description' => 'x', 'quantity' => 1]]])->assertCreated();
    $number = $response->json('data.number');

    Mail::assertQueued(QuoteSubmittedMail::class, function (QuoteSubmittedMail $mail) use ($number) {
        $html = $mail->render();

        return $mail->url === "https://portal.example.test/quotes/{$number}" && str_contains($html, "https://portal.example.test/quotes/{$number}") && ! str_contains($html, 'evil.test');
    });
});

it('logs a warning and still creates the quote when there is nobody to notify', function () {
    config(['quotes.notify_address' => null]);
    $this->w->staff->delete();
    $this->w->admin->delete();
    Log::spy();

    loginAs($this->w->a1);
    submitQuote()->assertCreated();

    Mail::assertNothingQueued();
    Log::shouldHaveReceived('warning')->once();
    expect(QuoteRequest::count())->toBe(1);
});

it('does not turn a mail failure into a 500', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
    loginAs($this->w->a1);

    submitQuote()->assertCreated();

    expect(QuoteRequest::count())->toBe(1);
});

it('sends no mail when validation fails or the transaction rolls back', function () {
    loginAs($this->w->a1);
    $this->postJson('/api/v1/quotes', ['lines' => []])->assertStatus(422);
    $this->postJson('/api/v1/quotes', ['lines' => [['product_id' => 99999999, 'quantity' => 1]]])->assertStatus(422);

    QuoteStatusHistory::creating(fn () => throw new RuntimeException('boom'));
    try {
        submitQuote()->assertStatus(500);
    } finally {
        QuoteStatusHistory::flushEventListeners();
    }

    Mail::assertNothingQueued();
    expect(QuoteRequest::count())->toBe(0);
});

it('queues QuoteSentMail to the creator only, once per send, and again after a reopen', function () {
    $w = $this->w;
    $quote = makeQuote($w, 'inReview', ['valid_until' => '2026-12-31']);
    QuoteItem::where('quote_request_id', $quote->id)->update(['unit_price_cents' => 100, 'line_total_cents' => DB::raw('100 * quantity')]);
    $n = $quote->quote_number;

    loginAs($w->staff)->postJson("/api/v1/quotes/{$n}/send")->assertOk();
    Mail::assertQueued(QuoteSentMail::class, 1);
    Mail::assertQueued(QuoteSentMail::class, fn (QuoteSentMail $mail) => $mail->hasTo($w->a1->email) && ! $mail->hasTo($w->a2->email) && count($mail->to) === 1);

    // Failed sends and non-sends queue nothing.
    $this->postJson("/api/v1/quotes/{$n}/send")->assertStatus(422);
    $this->postJson("/api/v1/quotes/{$n}/reopen")->assertOk();
    Mail::assertQueued(QuoteSentMail::class, 1);

    $this->postJson("/api/v1/quotes/{$n}/send")->assertOk();
    Mail::assertQueued(QuoteSentMail::class, 2);
    Mail::assertNotQueued(QuoteSubmittedMail::class);
});

it('implements ShouldQueue with retries and backoff on both mailables', function () {
    $submitted = new QuoteSubmittedMail('QUO-AAAAA-AAAAA', 'Acme', 'Ann', 1, null, 'http://x.test/quotes/QUO-AAAAA-AAAAA');
    $sent = new QuoteSentMail('QUO-AAAAA-AAAAA', '1.00 USD', '2026-12-31', 1, 'http://x.test/quotes/QUO-AAAAA-AAAAA');

    foreach ([$submitted, $sent] as $mail) {
        expect($mail)->toBeInstanceOf(ShouldQueue::class)->and($mail->tries)->toBe(3)->and($mail->backoff)->toBe([60, 300]);
    }
});

it('escapes names in the email body', function () {
    $mail = new QuoteSubmittedMail('QUO-AAAAA-AAAAA', 'Acme <b>Ltd</b>', '<script>alert(1)</script>', 1, null, 'http://x.test/quotes/QUO-AAAAA-AAAAA');

    expect($mail->render())->not->toContain('<script>')->not->toContain('<b>Ltd</b>');
});

it('sends one mail per staff recipient, in id order, capped at max_notify_recipients with a warning', function () {
    config(['quotes.notify_address' => null, 'quotes.max_notify_recipients' => 3]);
    Log::spy();
    $extra = [makeUser('staff'), makeUser('staff'), makeUser('staff')];
    loginAs($this->w->a1);
    submitQuote()->assertCreated();

    // Fixture staff, fixture admin and the first extra staff user: ids 1..3 of the 5 staff-side users.
    Mail::assertQueued(QuoteSubmittedMail::class, 3);
    $queued = Mail::queued(QuoteSubmittedMail::class)->map(fn ($mail) => $mail->to[0]['address'])->all();
    expect($queued)->toBe([$this->w->staff->email, $this->w->admin->email, $extra[0]->email])
        ->and(Mail::queued(QuoteSubmittedMail::class)->every(fn ($mail) => count($mail->to) === 1))->toBeTrue();
    Log::shouldHaveReceived('warning')->once();
});

it('does not warn when the recipients fit under the cap', function () {
    config(['quotes.notify_address' => null, 'quotes.max_notify_recipients' => 2]);
    Log::spy();
    loginAs($this->w->a1);
    submitQuote()->assertCreated();

    Mail::assertQueued(QuoteSubmittedMail::class, 2);
    Log::shouldNotHaveReceived('warning');
});

it('keeps queueing for the other recipients when one address fails', function () {
    config(['quotes.notify_address' => null]);
    $pending = Mockery::mock();
    $pending->shouldReceive('queue')->once();
    $calls = 0;
    Mail::shouldReceive('to')->twice()->andReturnUsing(function () use (&$calls, $pending) {
        if (++$calls === 1) {
            throw new RuntimeException('bad address');
        }

        return $pending;
    });
    loginAs($this->w->a1);

    submitQuote()->assertCreated();

    expect($calls)->toBe(2);
});
