<?php

use App\Models\Order;
use App\Models\QuoteRequest;
use App\Models\QuoteStatusHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** The matrix written out independently of the code under test: status => actions that are allowed (by anyone entitled to them). */
const QUOTE_ALLOWED = [
    'submitted' => ['review', 'reject', 'decline', 'attach', 'note'],
    'inReview' => ['price', 'send', 'reject', 'decline', 'attach', 'note'],
    'quoted' => ['reopen', 'accept', 'decline', 'note'],
    'accepted' => ['note'],
    'declined' => ['note'],
    'expired' => ['note'],
];

beforeEach(function () {
    Mail::fake();
    Storage::fake('quotes');
    $this->travelTo(Carbon::parse('2026-11-25 12:00:00', 'UTC'));
    $this->w = quoteWorld();
});

afterEach(fn () => $this->travelBack());

/** A quote of company A in the state; in-review quotes are fully priced with a date so that send can succeed. */
function matrixQuote(object $w, string $state): QuoteRequest
{
    $quote = QuoteRequest::factory()->forCompany($w->a, $w->a1)->{$state}()
        ->withLines([[$w->priced, 10, $state === 'inReview' ? 100 : null], ['Custom thing', 4, $state === 'inReview' ? 100 : null]])
        ->create($state === 'inReview' ? ['valid_until' => '2026-12-31'] : []);

    return $quote;
}

/** [method, uri suffix, body, who]: who is customer or staff. */
function matrixCall(string $action, string $n): array
{
    return match ($action) {
        'review' => ['postJson', "/api/v1/quotes/{$n}/review", [], 'staff'],
        'price' => ['putJson', "/api/v1/quotes/{$n}/pricing", ['lines' => [['position' => 1, 'unit_price_cents' => 150]]], 'staff'],
        'send' => ['postJson', "/api/v1/quotes/{$n}/send", [], 'staff'],
        'reject' => ['postJson', "/api/v1/quotes/{$n}/reject", ['reason' => 'No stock'], 'staff'],
        'reopen' => ['postJson', "/api/v1/quotes/{$n}/reopen", [], 'staff'],
        'note' => ['putJson', "/api/v1/quotes/{$n}/internal-note", ['internal_note' => 'n'], 'staff'],
        'accept' => ['postJson', "/api/v1/quotes/{$n}/accept", ['revision' => 1], 'customer'],
        'decline' => ['postJson', "/api/v1/quotes/{$n}/decline", ['reason' => 'No thanks'], 'customer'],
    };
}

it('allows exactly the documented transitions and answers every other one with 422 on status', function (string $state, string $action) {
    $w = $this->w;
    $quote = matrixQuote($w, $state);
    [$method, $uri, $body, $who] = matrixCall($action, $quote->quote_number);
    $historyBefore = QuoteStatusHistory::count();
    $statusBefore = $quote->fresh()->status;

    $response = loginAs($who === 'staff' ? $w->staff : $w->a1)->{$method}($uri, $body);

    if (in_array($action, QUOTE_ALLOWED[$state], true)) {
        $response->assertOk();
    } else {
        $response->assertStatus(422)->assertJsonValidationErrors('status');
        expect($quote->fresh()->status)->toBe($statusBefore)->and(QuoteStatusHistory::count())->toBe($historyBefore)->and(Order::count())->toBe($state === 'accepted' ? 1 : 0);
    }
})->with(function () {
    foreach (array_keys(QUOTE_ALLOWED) as $state) {
        foreach (['review', 'price', 'send', 'reject', 'reopen', 'note', 'accept', 'decline'] as $action) {
            yield "{$state} / {$action}" => [$state, $action];
        }
    }
});

it('writes exactly one history row per status change and none for notes and pricing', function (string $state, string $action, int $rows) {
    $quote = matrixQuote($this->w, $state);
    [$method, $uri, $body, $who] = matrixCall($action, $quote->quote_number);
    $before = QuoteStatusHistory::where('quote_request_id', $quote->id)->count();

    loginAs($who === 'staff' ? $this->w->staff : $this->w->a1)->{$method}($uri, $body)->assertOk();

    expect(QuoteStatusHistory::where('quote_request_id', $quote->id)->count() - $before)->toBe($rows);
})->with([
    'review' => ['submitted', 'review', 1], 'withdraw' => ['submitted', 'decline', 1], 'reject' => ['inReview', 'reject', 1], 'send' => ['inReview', 'send', 1],
    'price' => ['inReview', 'price', 0], 'note' => ['quoted', 'note', 0], 'reopen' => ['quoted', 'reopen', 1], 'accept' => ['quoted', 'accept', 1], 'decline quoted' => ['quoted', 'decline', 1],
]);

it('refuses a customer on staff actions and staff on customer actions before looking at the quote', function (string $action) {
    $w = $this->w;
    $quote = matrixQuote($w, 'quoted');
    [$method, $uri, $body, $who] = matrixCall($action, $quote->quote_number);
    [, $unknownUri] = matrixCall($action, 'QUO-AAAAA-AAAAA');

    $wrong = $who === 'staff' ? [$w->a1, $w->a2, $w->b1] : [$w->staff, $w->admin];
    foreach ($wrong as $user) {
        loginAs($user);
        $this->{$method}($uri, $body)->assertForbidden();
        $this->{$method}($unknownUri, $body)->assertForbidden();
        // Even an invalid body is a 403, not a 422.
        $this->{$method}($uri, ['lines' => 'x', 'revision' => 'x', 'reason' => [], 'internal_note' => []])->assertForbidden();
    }

    expect($quote->fresh()->status->value)->toBe('quoted');
})->with(['review', 'price', 'send', 'reject', 'reopen', 'note', 'accept', 'decline']);

it('returns allowed_actions from the same matrix for each viewer', function (string $state, array $customer, array $staff) {
    $w = $this->w;
    $quote = matrixQuote($w, $state);
    $uri = "/api/v1/quotes/{$quote->quote_number}";

    expect(loginAs($w->a1)->getJson($uri)->json('data.allowed_actions'))->toBe($customer)
        ->and(loginAs($w->a2)->getJson($uri)->json('data.allowed_actions'))->toBe($customer)
        ->and(loginAs($w->staff)->getJson($uri)->json('data.allowed_actions'))->toBe($staff)
        ->and(loginAs($w->admin)->getJson($uri)->json('data.allowed_actions'))->toBe($staff);
})->with([
    'submitted' => ['submitted', ['decline', 'attach'], ['review', 'reject', 'attach', 'note']],
    'in review' => ['inReview', ['decline', 'attach'], ['price', 'send', 'reject', 'attach', 'note']],
    'quoted' => ['quoted', ['accept', 'decline'], ['reopen', 'note']],
    'accepted' => ['accepted', [], ['note']],
    'declined' => ['declined', [], ['note']],
    'expired' => ['expired', [], ['note']],
]);

it('lets customers withdraw before sending and staff reject, with the actor recorded', function () {
    $w = $this->w;
    $quote = matrixQuote($w, 'submitted');

    loginAs($w->a2)->postJson("/api/v1/quotes/{$quote->quote_number}/decline", ['reason' => "Found it\nelsewhere"])->assertOk()->assertJsonPath('data.status', 'declined');

    $row = QuoteStatusHistory::where('quote_request_id', $quote->id)->orderByDesc('id')->first();
    expect($row->from_status->value)->toBe('submitted')->and($row->to_status->value)->toBe('declined')->and($row->actor_user_id)->toBe($w->a2->id)->and($row->note)->toBe("Found it\nelsewhere");

    // Staff see the customer's reason; reasons are validated.
    $history = loginAs($w->staff)->getJson("/api/v1/quotes/{$quote->quote_number}")->json('data.history');
    expect(end($history)['note'])->toBe("Found it\nelsewhere")->and(end($history)['actor'])->toBe(['name' => $w->a2->name, 'role' => 'customer']);

    $other = matrixQuote($w, 'quoted');
    loginAs($w->a1);
    foreach ([['reason' => str_repeat('r', 501)], ['reason' => "a\0b"], ['reason' => ['x']], ['reason' => "a\x1Bb"]] as $body) {
        $this->postJson("/api/v1/quotes/{$other->quote_number}/decline", $body)->assertStatus(422)->assertJsonValidationErrors('reason');
    }
    $this->postJson("/api/v1/quotes/{$other->quote_number}/decline")->assertOk()->assertJsonPath('data.history.3.note', null);
});

it('refuses repeat and out of order actions from a stale view', function () {
    $w = $this->w;
    $quote = matrixQuote($w, 'inReview');
    $n = $quote->quote_number;

    // The customer withdraws; staff then try to send.
    loginAs($w->a1)->postJson("/api/v1/quotes/{$n}/decline")->assertOk();
    loginAs($w->staff)->postJson("/api/v1/quotes/{$n}/send")->assertStatus(422)->assertJsonValidationErrors('status');
    loginAs($w->a1)->postJson("/api/v1/quotes/{$n}/accept", ['revision' => 1])->assertStatus(422)->assertJsonValidationErrors('status');
    loginAs($w->a1)->postJson("/api/v1/quotes/{$n}/decline")->assertStatus(422)->assertJsonValidationErrors('status');

    expect(Order::count())->toBe(0);
    Mail::assertNothingQueued();
});
