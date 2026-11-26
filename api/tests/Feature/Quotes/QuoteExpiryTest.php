<?php

use App\Models\Order;
use App\Models\QuoteRequest;
use App\Models\QuoteStatusHistory;
use App\Services\QuoteWorkflow;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Mail::fake();
    Storage::fake('quotes');
    $this->w = quoteWorld();
    $this->quote = makeQuote($this->w, 'quoted', ['valid_until' => '2026-12-31']);
    $this->n = $this->quote->quote_number;
});

afterEach(function () {
    Carbon::setTestNow();
    $this->travelBack();
});

it('is still acceptable at the last second of valid_until and expired one second later', function () {
    $w = $this->w;
    $this->travelTo(Carbon::parse('2026-12-31 23:59:59', 'UTC'));
    loginAs($w->a1)->getJson("/api/v1/quotes/{$this->n}")->assertOk()->assertJsonPath('data.status', 'quoted')
        ->assertJsonPath('data.expires_at', '2027-01-01T00:00:00Z')->assertJsonPath('data.allowed_actions', ['accept', 'decline']);
    $second = makeQuote($w, 'quoted', ['valid_until' => '2026-12-31']);
    $this->postJson("/api/v1/quotes/{$second->quote_number}/accept", ['revision' => 1])->assertOk();

    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'UTC'));
    $view = $this->getJson("/api/v1/quotes/{$this->n}")->assertOk();
    expect($view->json('data.status'))->toBe('expired')->and($view->json('data.allowed_actions'))->toBe([])
        ->and($this->quote->fresh()->status->value)->toBe('quoted'); // not materialised yet

    $this->postJson("/api/v1/quotes/{$this->n}/accept", ['revision' => 1])->assertStatus(422)->assertJsonPath('errors.status.0', 'This quote has expired.');
    $this->postJson("/api/v1/quotes/{$this->n}/decline")->assertStatus(422)->assertJsonValidationErrors('status');
    loginAs($w->staff)->postJson("/api/v1/quotes/{$this->n}/reopen")->assertStatus(422)->assertJsonValidationErrors('status');
    loginAs($w->staff)->getJson("/api/v1/quotes/{$this->n}")->assertJsonPath('data.allowed_actions', ['note'])->assertJsonPath('data.status', 'expired');

    expect(Order::where('quote_request_id', $this->quote->id)->count())->toBe(0)
        ->and(QuoteStatusHistory::where('quote_request_id', $this->quote->id)->where('to_status', 'expired')->count())->toBe(0);
});

it('uses UTC whatever the timezone of the clock object', function (string $instant, string $zone, string $expected) {
    Carbon::setTestNow(Carbon::parse($instant, $zone));

    loginAs($this->w->a1)->getJson("/api/v1/quotes/{$this->n}")->assertOk()->assertJsonPath('data.status', $expected);
})->with([
    // 23:30 in Los Angeles on 31 Dec is 07:30 UTC on 1 Jan: expired.
    'late evening in the west' => ['2026-12-31 23:30:00', 'America/Los_Angeles', 'expired'],
    // 00:30 in Tokyo on 1 Jan is 15:30 UTC on 31 Dec: still valid.
    'early morning in the east' => ['2027-01-01 00:30:00', 'Asia/Tokyo', 'quoted'],
    'plain UTC boundary' => ['2026-12-31 23:59:59', 'UTC', 'quoted'],
]);

it('materialises expiry with quotes:expire: a system history row, once, no email', function () {
    $this->travelTo(Carbon::parse('2027-01-01 00:00:01', 'UTC'));

    $this->artisan('quotes:expire')->expectsOutput('Expired 1 quote(s).')->assertSuccessful();

    $row = QuoteStatusHistory::where('quote_request_id', $this->quote->id)->orderByDesc('id')->first();
    expect($this->quote->fresh()->status->value)->toBe('expired')->and($row->from_status->value)->toBe('quoted')->and($row->to_status->value)->toBe('expired')
        ->and($row->actor_user_id)->toBeNull();

    $count = QuoteStatusHistory::count();
    $this->artisan('quotes:expire')->expectsOutput('Expired 0 quote(s).')->assertSuccessful();
    expect(QuoteStatusHistory::count())->toBe($count);
    Mail::assertNothingQueued();

    $history = loginAs($this->w->a1)->getJson("/api/v1/quotes/{$this->n}")->assertJsonPath('data.status', 'expired')->json('data.history');
    expect(end($history)['actor'])->toBe(['name' => null, 'role' => 'system']);
});

it('leaves the boundary day, other statuses and unexpired quotes alone', function () {
    $w = $this->w;
    $other = [makeQuote($w, 'submitted'), makeQuote($w, 'inReview'), makeQuote($w, 'accepted'), makeQuote($w, 'declined'), makeQuote($w, 'expired'), makeQuote($w, 'quoted', ['valid_until' => '2027-06-30'])];
    $this->travelTo(Carbon::parse('2026-12-31 23:59:59', 'UTC'));
    $before = QuoteStatusHistory::count();

    $this->artisan('quotes:expire')->expectsOutput('Expired 0 quote(s).')->assertSuccessful();

    expect(QuoteStatusHistory::count())->toBe($before)->and($this->quote->fresh()->status->value)->toBe('quoted');
    foreach ($other as $quote) {
        expect($quote->fresh()->status)->toBe($quote->status);
    }
});

it('never expires a quote that changed state between selection and lock', function () {
    $this->travelTo(Carbon::parse('2027-01-01 00:00:01', 'UTC'));
    $workflow = app(QuoteWorkflow::class);
    $this->quote->forceFill(['status' => 'in_review'])->save();

    expect($workflow->expire($this->quote->id))->toBeFalse()->and($workflow->expire(999999))->toBeFalse();
});

it('is scheduled every five minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'quotes:expire'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/5 * * * *')->and($event->withoutOverlapping)->toBeTrue();
});

it('refuses uploads on an expired quote', function () {
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'UTC'));

    loginAs($this->w->a1);
    uploadTo($this->n, fakeUpload('a.pdf', pdfBytes()))->assertStatus(422)->assertJsonValidationErrors('status');
    expect(QuoteRequest::find($this->quote->id)->attachments()->count())->toBe(0);
});
