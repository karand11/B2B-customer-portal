<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\QuoteRequest;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-27 10:00:00', 'UTC'));
    $this->w = quoteWorld();
});

afterEach(fn () => $this->travelBack());

function additiveQuoteNumbers(string $query)
{
    return array_column(test()->getJson('/api/v1/quotes'.$query)->assertOk()->json('data'), 'number');
}

function additiveInvoice(string $due, string $state = 'open', ?Company $company = null): Invoice
{
    $order = orderWith($company ?? test()->w->a, [[1000, 1]], 'confirmed');

    return Invoice::factory()->forOrder($order)->{$state}()->issuedOn('2026-10-01')->dueOn($due)->create();
}

it('filters quotes that expire within N days: stored quoted, valid today through today + N', function () {
    $w = $this->w;
    $today = makeQuote($w, 'quoted', ['valid_until' => '2026-11-27']);
    $edge = makeQuote($w, 'quoted', ['valid_until' => '2026-12-04']);
    makeQuote($w, 'quoted', ['valid_until' => '2026-12-05']); // today + 8
    makeQuote($w, 'quoted', ['valid_until' => '2026-11-26']); // already expired
    makeQuote($w, 'inReview', ['valid_until' => '2026-11-28']); // not quoted
    makeQuote($w, 'accepted', ['valid_until' => '2026-11-28']);
    $this->actingAs($w->staff);

    expect(additiveQuoteNumbers('?expiring_within_days=7&sort=number'))->toEqualCanonicalizing([$today->quote_number, $edge->quote_number])
        ->and(additiveQuoteNumbers('?expiring_within_days=1'))->toBe([$today->quote_number])
        ->and(additiveQuoteNumbers('?expiring_within_days=30'))->toHaveCount(3)
        ->and(additiveQuoteNumbers('?status=quoted&expiring_within_days=7'))->toHaveCount(2)
        ->and(additiveQuoteNumbers('?status=expired&expiring_within_days=7'))->toBe([]);
});

it('keeps the quote filter inside the company of a customer', function () {
    $w = $this->w;
    $mine = makeQuote($w, 'quoted', ['valid_until' => '2026-11-30']);
    $theirs = additiveForeignQuote($w);
    $this->actingAs($w->a1);

    expect(additiveQuoteNumbers('?expiring_within_days=7'))->toBe([$mine->quote_number]);
    $this->actingAs($w->b1);
    expect(additiveQuoteNumbers('?expiring_within_days=7'))->toBe([$theirs->quote_number]);
});

function additiveForeignQuote(object $w): QuoteRequest
{
    return QuoteRequest::factory()->forCompany($w->b, $w->b1)->quoted()->withLines([[$w->priced, 1, null]])->create(['valid_until' => '2026-11-29']);
}

it('refuses a bad expiring_within_days on the parameter name', function (mixed $value) {
    $this->actingAs($this->w->staff);

    $this->getJson('/api/v1/quotes?'.http_build_query(['expiring_within_days' => $value]))->assertStatus(422)->assertJsonValidationErrors('expiring_within_days');
})->with(['0', '31', 'x', '1.5', '-1', '1e1']);

it('refuses array syntax for expiring_within_days', function () {
    $this->actingAs($this->w->staff)->getJson('/api/v1/quotes?expiring_within_days[]=7')->assertStatus(422)->assertJsonValidationErrors('expiring_within_days');
});

it('filters invoices that are effectively open and due within N days, 0 meaning today', function () {
    $today = additiveInvoice('2026-11-27');
    $edge = additiveInvoice('2026-12-04');
    additiveInvoice('2026-12-05'); // today + 8
    additiveInvoice('2026-11-26'); // stored open but past due: effectively overdue
    additiveInvoice('2026-11-01', 'overdue');
    additiveInvoice('2026-11-28', 'paid');
    additiveInvoice('2026-11-28', 'void');
    $this->actingAs($this->w->staff);
    $numbers = fn (string $q) => array_column($this->getJson('/api/v1/invoices'.$q)->assertOk()->json('data'), 'number');

    expect($numbers('?due_within_days=0'))->toBe([$today->number])
        ->and($numbers('?due_within_days=7'))->toEqualCanonicalizing([$today->number, $edge->number])
        ->and($numbers('?due_within_days=30'))->toHaveCount(3)
        ->and($numbers('?status=open&due_within_days=7'))->toHaveCount(2)
        ->and($numbers('?status=overdue&due_within_days=7'))->toBe([]);
});

it('keeps the invoice filter inside the company of a customer', function () {
    $mine = additiveInvoice('2026-11-30');
    additiveInvoice('2026-11-30', 'open', $this->w->b);

    $this->actingAs($this->w->a1);
    expect(array_column($this->getJson('/api/v1/invoices?due_within_days=7')->assertOk()->json('data'), 'number'))->toBe([$mine->number]);
});

it('refuses a bad due_within_days on the parameter name', function (string $value) {
    $this->actingAs($this->w->staff);

    $this->getJson('/api/v1/invoices?due_within_days='.$value)->assertStatus(422)->assertJsonValidationErrors('due_within_days');
})->with(['-1', '31', 'x', '1.5']);

it('treats an empty due_within_days as absent and refuses array syntax', function () {
    $this->actingAs($this->w->staff);

    $this->getJson('/api/v1/invoices?due_within_days=')->assertOk();
    $this->getJson('/api/v1/invoices?due_within_days[]=1')->assertStatus(422)->assertJsonValidationErrors('due_within_days');
});
