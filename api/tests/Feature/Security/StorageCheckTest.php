<?php

use App\Models\QuoteAttachment;
use App\Models\QuoteRequest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LogCapture;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-25 12:00:00', 'UTC'));
    Storage::fake('quotes');
    Storage::fake('imports');
    $this->w = quoteWorld();
    $this->quoteA = makeQuote($this->w, 'quoted');
    $this->quoteB = secStorageQuoteOfB($this->w);
});

afterEach(fn () => $this->travelBack());

function secStorageQuoteOfB(object $w)
{
    return QuoteRequest::factory()->forCompany($w->b, $w->b1)->quoted()->create();
}

/** Run the command and return [exit code, decoded --json report]. */
function secStorageReport(array $options = []): array
{
    $code = Artisan::call('quotes:storage-check', ['--json' => true] + $options);

    return [$code, json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR)];
}

/** A file on the quotes disk with no database row, last modified $hours ago. */
function secOrphanFile(string $name, int $hours): string
{
    $path = 'quote-attachments/'.$name;
    Storage::disk('quotes')->put($path, 'orphan bytes');
    touch(Storage::disk('quotes')->path($path), now()->subHours($hours)->getTimestamp());

    return $path;
}

it('reports files and bytes per company and in total, plus the import disk and free space', function () {
    QuoteAttachment::factory()->count(2)->create(['quote_request_id' => $this->quoteA->id, 'size_bytes' => 1000]);
    QuoteAttachment::factory()->create(['quote_request_id' => $this->quoteB->id, 'size_bytes' => 250]);
    Storage::disk('imports')->put('x.csv', '12345');

    [$code, $report] = secStorageReport();

    expect($code)->toBe(0)
        ->and($report['quotes']['files'])->toBe(3)->and($report['quotes']['bytes'])->toBe(2250)
        ->and(collect($report['quotes']['companies'])->keyBy('company_id')->map(fn ($c) => [$c['files'], $c['bytes']])->all())
        ->toBe([$this->w->a->id => [2, 2000], $this->w->b->id => [1, 250]])
        ->and($report['imports_bytes'])->toBe(5)
        ->and($report['free_bytes'])->toBeInt()->toBeGreaterThan(0)
        ->and($report['orphan_files'])->toBe([])->and($report['rows_without_file'])->toBe([])->and($report['pruned'])->toBe([]);
});

it('lists files without a row older than 24 hours, never a younger one (an upload may be in flight), never a file that has a row', function () {
    $known = QuoteAttachment::factory()->create(['quote_request_id' => $this->quoteA->id]);
    $old = secOrphanFile('old-orphan', 30);
    secOrphanFile('fresh-orphan', 2);

    [, $report] = secStorageReport();

    expect($report['orphan_files'])->toBe([$old])->and($report['orphan_files'])->not->toContain($known->storage_path);
});

it('lists rows whose file is missing, by public id', function () {
    $ok = QuoteAttachment::factory()->create(['quote_request_id' => $this->quoteA->id]);
    $gone = QuoteAttachment::factory()->create(['quote_request_id' => $this->quoteA->id]);
    Storage::disk('quotes')->delete($gone->storage_path);

    [, $report] = secStorageReport();

    expect($report['rows_without_file'])->toBe([$gone->public_id])->and($report['rows_without_file'])->not->toContain($ok->public_id);
});

it('never deletes anything by default, and --prune-orphans without --force is a dry run', function () {
    $old = secOrphanFile('old-orphan', 30);
    $gone = QuoteAttachment::factory()->create(['quote_request_id' => $this->quoteA->id]);
    Storage::disk('quotes')->delete($gone->storage_path);

    secStorageReport();
    [, $dry] = secStorageReport(['--prune-orphans' => true]);

    expect(Storage::disk('quotes')->exists($old))->toBeTrue()->and($dry['pruned'])->toBe([])->and(QuoteAttachment::whereKey($gone->id)->exists())->toBeTrue();
    expect(Artisan::call('quotes:storage-check', ['--prune-orphans' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Dry run');
});

it('with --prune-orphans --force deletes orphan FILES only: never a young file, a file with a row, or a row', function () {
    $old = secOrphanFile('old-orphan', 30);
    $young = secOrphanFile('young-orphan', 1);
    $known = QuoteAttachment::factory()->create(['quote_request_id' => $this->quoteA->id]);
    $gone = QuoteAttachment::factory()->create(['quote_request_id' => $this->quoteA->id]);
    Storage::disk('quotes')->delete($gone->storage_path);

    [$code, $report] = secStorageReport(['--prune-orphans' => true, '--force' => true]);

    expect($code)->toBe(0)->and($report['pruned'])->toBe([$old])
        ->and(Storage::disk('quotes')->exists($old))->toBeFalse()
        ->and(Storage::disk('quotes')->exists($young))->toBeTrue()
        ->and(Storage::disk('quotes')->exists($known->storage_path))->toBeTrue()
        ->and(QuoteAttachment::count())->toBe(2);
});

it('logs a warning for a company above 80 percent of its cap, a low disk, and orphans, and none when all is well', function () {
    $logs = LogCapture::start();
    config(['portal.storage_min_free_bytes' => 1, 'quotes.attachments.max_company_bytes' => 10000]);
    QuoteAttachment::factory()->create(['quote_request_id' => $this->quoteA->id, 'size_bytes' => 7900]);
    secStorageReport();
    expect(array_column(LogCapture::records($logs), 'message'))->not->toContain('Quote storage: a company is close to its attachment cap')
        ->and(array_column(LogCapture::records($logs), 'level'))->not->toContain('WARNING');

    QuoteAttachment::factory()->create(['quote_request_id' => $this->quoteB->id, 'size_bytes' => 8500]);
    secOrphanFile('old-orphan', 48);
    config(['portal.storage_min_free_bytes' => PHP_INT_MAX]);
    secStorageReport();

    $messages = array_column(LogCapture::records($logs), 'message');
    expect($messages)->toContain('Quote storage: a company is close to its attachment cap')
        ->toContain('Quote storage: free disk space is low')->toContain('Quote storage: orphans found');
    $company = collect(LogCapture::records($logs))->firstWhere('message', 'Quote storage: a company is close to its attachment cap');
    expect($company['context'])->toBe(['company_id' => $this->w->b->id, 'percent' => 85.0]);
});

it('prints a readable report without --json', function () {
    QuoteAttachment::factory()->create(['quote_request_id' => $this->quoteA->id, 'size_bytes' => 4096]);

    expect(Artisan::call('quotes:storage-check'))->toBe(0);
    expect(Artisan::output())->toContain('Quote attachments: 1 files, 4,096 bytes')->toContain('Free disk space:')->toContain('Rows without a file: 0');
});

it('is scheduled once a day, read-only, together with the other Phase 7 jobs', function () {
    Artisan::call('schedule:list'); // loads routes/console.php
    $list = Artisan::output();
    $events = collect(app(Schedule::class)->events());

    expect($list)->toContain('quotes:storage-check')->not->toContain('--prune-orphans')->not->toContain('--force')
        ->toContain('queue:monitor database:default --max=100')->toContain('queue:prune-failed --hours=720');
    expect($events->contains(fn ($e) => $e->description === 'scheduler-heartbeat' && $e->expression === '* * * * *'))->toBeTrue();
});
