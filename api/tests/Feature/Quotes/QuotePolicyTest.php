<?php

use App\Models\QuoteAttachment;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Policies\QuotePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('quotes');
    $w = quoteWorld();
    $w->norole = User::factory()->create();
    $w->nocompany = makeUser('customer');
    $w->nocompany->forceFill(['company_id' => null])->save();
    $this->w = $w;
    $this->quote = makeQuote($w, 'quoted');
    $this->policy = new QuotePolicy;
});

/** True when allowed, 'hidden' for a denyAsNotFound (404), false for a plain deny (403). */
function quoteVerdict(Response|bool $result): bool|string
{
    if (is_bool($result)) {
        return $result;
    }

    return $result->allowed() ? true : ($result->status() === 404 ? 'hidden' : false);
}

it('applies the view rule: own company, staff and admin only, foreign is hidden as 404', function () {
    $w = $this->w;

    expect(quoteVerdict($this->policy->view($w->a1, $this->quote)))->toBeTrue()
        ->and(quoteVerdict($this->policy->view($w->a2, $this->quote)))->toBeTrue()
        ->and(quoteVerdict($this->policy->view($w->staff, $this->quote)))->toBeTrue()
        ->and(quoteVerdict($this->policy->view($w->admin, $this->quote)))->toBeTrue()
        ->and(quoteVerdict($this->policy->view($w->b1, $this->quote)))->toBe('hidden')
        ->and(quoteVerdict($this->policy->view($w->nocompany, $this->quote)))->toBe('hidden')
        ->and(quoteVerdict($this->policy->view($w->norole, $this->quote)))->toBe('hidden');
});

it('applies the create rule: customers with a company only', function () {
    $w = $this->w;

    expect($this->policy->create($w->a1))->toBeTrue()->and($this->policy->create($w->staff))->toBeFalse()->and($this->policy->create($w->admin))->toBeFalse()
        ->and($this->policy->create($w->nocompany))->toBeFalse()->and($this->policy->create($w->norole))->toBeFalse();
});

it('applies the customer-action rule to accept and decline', function (string $method) {
    $w = $this->w;

    expect(quoteVerdict($this->policy->{$method}($w->a1, $this->quote)))->toBeTrue()
        ->and(quoteVerdict($this->policy->{$method}($w->a2, $this->quote)))->toBeTrue()
        ->and(quoteVerdict($this->policy->{$method}($w->b1, $this->quote)))->toBe('hidden')
        ->and(quoteVerdict($this->policy->{$method}($w->nocompany, $this->quote)))->toBe('hidden')
        ->and(quoteVerdict($this->policy->{$method}($w->staff, $this->quote)))->toBeFalse()
        ->and(quoteVerdict($this->policy->{$method}($w->admin, $this->quote)))->toBeFalse()
        ->and(quoteVerdict($this->policy->{$method}($w->norole, $this->quote)))->toBeFalse();
})->with(['accept', 'decline']);

it('applies the staff-action rule to review, price, send, reject, reopen and note', function (string $method) {
    $w = $this->w;

    expect($this->policy->{$method}($w->staff, $this->quote))->toBeTrue()->and($this->policy->{$method}($w->admin, $this->quote))->toBeTrue()
        ->and($this->policy->{$method}($w->a1, $this->quote))->toBeFalse()->and($this->policy->{$method}($w->b1, $this->quote))->toBeFalse()
        ->and($this->policy->{$method}($w->nocompany, $this->quote))->toBeFalse()->and($this->policy->{$method}($w->norole, $this->quote))->toBeFalse();
})->with(['review', 'price', 'send', 'reject', 'reopen', 'note']);

it('lets customers of the company and staff attach, and hides the quote from everyone else', function () {
    $w = $this->w;

    expect(quoteVerdict($this->policy->attach($w->a1, $this->quote)))->toBeTrue()->and(quoteVerdict($this->policy->attach($w->staff, $this->quote)))->toBeTrue()
        ->and(quoteVerdict($this->policy->attach($w->admin, $this->quote)))->toBeTrue()
        ->and(quoteVerdict($this->policy->attach($w->b1, $this->quote)))->toBe('hidden')->and(quoteVerdict($this->policy->attach($w->nocompany, $this->quote)))->toBe('hidden')
        ->and(quoteVerdict($this->policy->attach($w->norole, $this->quote)))->toBe('hidden');
});

it('hides staff files from customers until sent, and lets only staff delete them', function () {
    $w = $this->w;
    $draft = makeQuote($w, 'inReview');
    $own = QuoteAttachment::factory()->create(['quote_request_id' => $draft->id]);
    $staff = QuoteAttachment::factory()->byStaff($w->staff)->create(['quote_request_id' => $draft->id]);
    $sent = makeQuote($w, 'quoted');
    $sentStaff = QuoteAttachment::factory()->byStaff($w->staff)->create(['quote_request_id' => $sent->id]);

    // Customer files: visible and deletable by the company, hidden from other companies.
    expect(quoteVerdict($this->policy->viewAttachment($w->a2, $draft, $own)))->toBeTrue()->and(quoteVerdict($this->policy->deleteAttachment($w->a2, $draft, $own)))->toBeTrue()
        ->and(quoteVerdict($this->policy->viewAttachment($w->b1, $draft, $own)))->toBe('hidden')->and(quoteVerdict($this->policy->deleteAttachment($w->b1, $draft, $own)))->toBe('hidden')
        ->and(quoteVerdict($this->policy->deleteAttachment($w->staff, $draft, $own)))->toBeTrue();

    // Staff files before sent_at.
    expect(quoteVerdict($this->policy->viewAttachment($w->a1, $draft, $staff)))->toBe('hidden')->and(quoteVerdict($this->policy->deleteAttachment($w->a1, $draft, $staff)))->toBe('hidden')
        ->and(quoteVerdict($this->policy->viewAttachment($w->staff, $draft, $staff)))->toBeTrue()->and(quoteVerdict($this->policy->viewAttachment($w->admin, $draft, $staff)))->toBeTrue()
        ->and(quoteVerdict($this->policy->deleteAttachment($w->admin, $draft, $staff)))->toBeTrue();

    // After sent_at the customer may see, but never delete, a staff file.
    expect(quoteVerdict($this->policy->viewAttachment($w->a1, $sent, $sentStaff)))->toBeTrue()->and(quoteVerdict($this->policy->deleteAttachment($w->a1, $sent, $sentStaff)))->toBe('hidden')
        ->and(quoteVerdict($this->policy->viewAttachment($w->b1, $sent, $sentStaff)))->toBe('hidden')->and(quoteVerdict($this->policy->viewAttachment($w->norole, $sent, $sentStaff)))->toBe('hidden');
});

it('is registered for the QuoteRequest model and has no blanket admin allow', function () {
    expect(Gate::getPolicyFor(QuoteRequest::class))->toBeInstanceOf(QuotePolicy::class)
        ->and(Gate::forUser($this->w->admin)->allows('accept', $this->quote))->toBeFalse()
        ->and(Gate::forUser($this->w->admin)->allows('view', $this->quote))->toBeTrue()
        ->and(Gate::forUser($this->w->b1)->denies('view', $this->quote))->toBeTrue();
});
