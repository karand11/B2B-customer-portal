<?php

use App\Models\QuoteAttachment;
use App\Models\QuoteRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-25 12:00:00', 'UTC'));
    Storage::fake('quotes');
    $this->w = quoteWorld();
});

afterEach(fn () => $this->travelBack());

/** A quote of company A in the given state with one customer file and one staff file. */
function secPurgeQuote(string $state): array
{
    $quote = makeQuote(test()->w, $state);
    $customerFile = QuoteAttachment::factory()->named('customer-drawing.pdf')->create(['quote_request_id' => $quote->id]);
    $staffFile = QuoteAttachment::factory()->byStaff(test()->w->staff)->named('our-offer.pdf')->create(['quote_request_id' => $quote->id]);

    return [$quote, $customerFile, $staffFile];
}

function secPurgeUri(QuoteRequest $quote, QuoteAttachment $file): string
{
    return "/api/v1/quotes/{$quote->quote_number}/attachments/{$file->public_id}";
}

it('lets staff and admin purge any attachment on a closed or sent quote and audits it with the exact metadata', function (string $state, string $who) {
    [$quote, $file] = secPurgeQuote($state);
    $actor = $who === 'staff' ? $this->w->staff : $this->w->admin;
    $sha = $file->sha256;
    $path = $file->storage_path;

    $this->actingAs($actor)->deleteJson(secPurgeUri($quote, $file))->assertNoContent();

    expect(QuoteAttachment::whereKey($file->id)->exists())->toBeFalse()->and(Storage::disk('quotes')->exists($path))->toBeFalse();
    $row = auditLogOne('quote.attachment_purged');
    auditLogExpect($row, $actor, 'quote', $quote->quote_number, $this->w->a->id, [], ['file_name' => 'customer-drawing.pdf', 'size_bytes' => $file->size_bytes, 'sha256' => $sha]);
    expect($row['subject_label'])->toBe($quote->quote_number);
})->with(['accepted', 'declined', 'expired', 'quoted'])->with(['staff', 'admin']);

it('audits a staff purge on an OPEN quote too, with the same allowlisted metadata (file name, size, sha256) and nothing else', function (string $state, string $which) {
    [$quote, $customerFile, $staffFile] = secPurgeQuote($state);
    $file = $which === 'customer file' ? $customerFile : $staffFile;

    $this->actingAs($this->w->staff)->deleteJson(secPurgeUri($quote, $file))->assertNoContent();

    expect(QuoteAttachment::whereKey($file->id)->exists())->toBeFalse();
    $row = auditLogOne('quote.attachment_purged');
    auditLogExpect($row, $this->w->staff, 'quote', $quote->quote_number, $this->w->a->id, [], ['file_name' => $file->original_name, 'size_bytes' => $file->size_bytes, 'sha256' => $file->sha256]);
    expect($row['subject_label'])->toBe($quote->quote_number)->and(json_encode($row))->not->toContain($file->storage_path)->not->toContain($file->public_id);
})->with(['submitted', 'inReview'])->with(['customer file', 'staff file']);

it('treats a quoted quote past its valid date (effective expired) as not open', function () {
    [$quote, $file] = secPurgeQuote('quoted');
    $quote->forceFill(['valid_until' => now()->utc()->subDays(3)])->save();

    $this->actingAs($this->w->staff)->deleteJson(secPurgeUri($quote, $file))->assertNoContent();

    expect(auditLogRows('quote.attachment_purged'))->toHaveCount(1);
});

it('keeps the customer rule: no delete once the quote is closed (422 status), no staff files, nobody else\'s quote', function () {
    [$quote, $customerFile, $staffFile] = secPurgeQuote('accepted');

    $this->actingAs($this->w->a1)->deleteJson(secPurgeUri($quote, $customerFile))->assertStatus(422)->assertJsonValidationErrors('status');
    $this->actingAs($this->w->a1)->deleteJson(secPurgeUri($quote, $staffFile))->assertNotFound();
    $this->actingAs($this->w->b1)->deleteJson(secPurgeUri($quote, $customerFile))->assertNotFound();

    expect(QuoteAttachment::count())->toBe(2)->and(auditLogRows('quote.attachment_purged'))->toBe([]);
});

it('still lets a customer delete their own file on an open quote, unaudited', function () {
    [$quote, $customerFile] = secPurgeQuote('submitted');

    $this->actingAs($this->w->a1)->deleteJson(secPurgeUri($quote, $customerFile))->assertNoContent();

    expect(auditLogRows())->toBe([]);
});

it('answers 404 for an unknown attachment or quote, also to staff, and writes nothing', function () {
    [$quote, $file] = secPurgeQuote('accepted');

    $this->actingAs($this->w->staff)->deleteJson("/api/v1/quotes/{$quote->quote_number}/attachments/00000000-0000-4000-8000-000000000000")->assertNotFound();
    $this->actingAs($this->w->staff)->deleteJson("/api/v1/quotes/QUO-AAAAA-AAAAA/attachments/{$file->public_id}")->assertNotFound();
    // a file of ANOTHER quote under this quote's number
    [, $other] = secPurgeQuote('declined');
    $this->actingAs($this->w->staff)->deleteJson(secPurgeUri($quote, $other))->assertNotFound();

    expect(auditLogRows('quote.attachment_purged'))->toBe([])->and(QuoteAttachment::count())->toBe(4);
});

it('does not fail the purge when the file is already gone from the disk', function () {
    [$quote, $file] = secPurgeQuote('accepted');
    Storage::disk('quotes')->delete($file->storage_path);

    $this->actingAs($this->w->staff)->deleteJson(secPurgeUri($quote, $file))->assertNoContent();

    expect(auditLogRows('quote.attachment_purged'))->toHaveCount(1);
});

it('shows the new action in the audit viewer and accepts it as a filter', function () {
    [$quote, $file] = secPurgeQuote('accepted');
    $this->actingAs($this->w->staff)->deleteJson(secPurgeUri($quote, $file))->assertNoContent();

    $response = $this->actingAs($this->w->admin)->getJson('/api/v1/admin/audit-log?action=quote.attachment_purged')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.metadata'))->toBe(['file_name' => 'customer-drawing.pdf', 'size_bytes' => $file->size_bytes, 'sha256' => $file->sha256])
        ->and($response->json('data.0.subject'))->toMatchArray(['type' => 'quote']);
});
