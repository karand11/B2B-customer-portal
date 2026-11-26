<?php

use App\Models\QuoteAttachment;
use App\Models\QuoteRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Mail::fake();
    Storage::fake('quotes');
    $this->travelTo(Carbon::parse('2026-12-31 12:00:00', 'UTC'));
    $this->w = quoteWorld();
    $w = $this->w;
    // Four Northwind quotes on known days, one Globex quote.
    $this->q1 = QuoteRequest::factory()->forCompany($w->a, $w->a1)->createdAt('2026-11-10 23:59:59')->create(['quote_number' => 'QUO-BBBBB-CCCCC', 'po_reference' => 'Job 12/B_7']);
    $this->q2 = QuoteRequest::factory()->forCompany($w->a, $w->a2)->inReview()->createdAt('2026-11-11 00:00:00')->create(['quote_number' => 'QUO-DDDDD-EEEEE', 'po_reference' => '50%_OFF']);
    $this->q3 = QuoteRequest::factory()->forCompany($w->a, $w->a1)->quoted()->validUntil('2026-12-31')->createdAt('2026-11-12 08:00:00')->create(['quote_number' => 'QUO-FFFFF-GGGGG']);
    $this->q4 = QuoteRequest::factory()->forCompany($w->a, $w->a1)->quoted()->validUntil('2026-12-30')->createdAt('2026-11-13 08:00:00')->create(['quote_number' => 'QUO-HHHHH-JJJJJ']);
    $this->q5 = QuoteRequest::factory()->forCompany($w->a, $w->a1)->expired()->createdAt('2026-11-14 08:00:00')->create(['quote_number' => 'QUO-KKKKK-MMMMM']);
    $this->b = QuoteRequest::factory()->forCompany($w->b, $w->b1)->createdAt('2026-11-12 09:00:00')->create(['quote_number' => 'QUO-NNNNN-PPPPP', 'po_reference' => 'Job 12/B_7']);
});

afterEach(fn () => $this->travelBack());

function quoteNumbers(string $query = ''): array
{
    return array_column(test()->getJson('/api/v1/quotes'.$query)->assertOk()->json('data'), 'number');
}

it('lists the company quotes newest first with exactly the contract keys and meta', function () {
    $response = loginAs($this->w->a1)->getJson('/api/v1/quotes')->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'meta'])
        ->and(array_keys($response->json('meta')))->toBe(['current_page', 'last_page', 'per_page', 'total', 'from', 'to'])
        ->and($response->json('meta'))->toBe(['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 5, 'from' => 1, 'to' => 5])
        ->and(array_column($response->json('data'), 'number'))->toBe(['QUO-KKKKK-MMMMM', 'QUO-HHHHH-JJJJJ', 'QUO-FFFFF-GGGGG', 'QUO-DDDDD-EEEEE', 'QUO-BBBBB-CCCCC'])
        ->and(array_keys($response->json('data.0')))->toBe(QUOTE_KEYS)
        ->and($response->json('data.2.line_count'))->toBe(2)
        ->and($response->json('data.4.created_at'))->toBe('2026-11-10T23:59:59Z')
        ->and($response->json('data.4.po_reference'))->toBe('Job 12/B_7');
});

it('filters by the effective status: expired and quoted follow valid_until, not just the stored column', function (string $at, string $query, array $expected) {
    $this->travelTo(Carbon::parse($at, 'UTC'));
    loginAs($this->w->a1);

    expect(quoteNumbers($query))->toBe($expected);
})->with([
    'submitted' => ['2026-12-31 12:00:00', '?status=submitted', ['QUO-BBBBB-CCCCC']],
    'in review' => ['2026-12-31 12:00:00', '?status=in_review', ['QUO-DDDDD-EEEEE']],
    'quoted excludes the past-validity row' => ['2026-12-31 12:00:00', '?status=quoted', ['QUO-FFFFF-GGGGG']],
    'expired includes the unmaterialised row' => ['2026-12-31 12:00:00', '?status=expired', ['QUO-KKKKK-MMMMM', 'QUO-HHHHH-JJJJJ']],
    'quoted and expired' => ['2026-12-31 12:00:00', '?status=quoted,expired', ['QUO-KKKKK-MMMMM', 'QUO-HHHHH-JJJJJ', 'QUO-FFFFF-GGGGG']],
    'csv with spaces and repeats' => ['2026-12-31 12:00:00', '?status=submitted,%20in_review,submitted', ['QUO-DDDDD-EEEEE', 'QUO-BBBBB-CCCCC']],
    'last second of the valid day' => ['2026-12-31 23:59:59', '?status=quoted', ['QUO-FFFFF-GGGGG']],
    'first second after' => ['2027-01-01 00:00:00', '?status=quoted', []],
    'expired after the boundary' => ['2027-01-01 00:00:00', '?status=expired', ['QUO-KKKKK-MMMMM', 'QUO-HHHHH-JJJJJ', 'QUO-FFFFF-GGGGG']],
]);

it('filters by created_at day in UTC, inclusive at both edges', function (string $query, array $expected) {
    loginAs($this->w->a1);

    expect(quoteNumbers($query))->toBe($expected);
})->with([
    'from the 11th' => ['?from=2026-11-11&sort=created_at', ['QUO-DDDDD-EEEEE', 'QUO-FFFFF-GGGGG', 'QUO-HHHHH-JJJJJ', 'QUO-KKKKK-MMMMM']],
    'to the 10th' => ['?to=2026-11-10', ['QUO-BBBBB-CCCCC']],
    'only the 11th' => ['?from=2026-11-11&to=2026-11-11', ['QUO-DDDDD-EEEEE']],
    'from equals to on the 10th' => ['?from=2026-11-10&to=2026-11-10', ['QUO-BBBBB-CCCCC']],
    'empty window' => ['?from=2027-01-01', []],
]);

it('searches the number (normalised, partial) and the PO reference (case-insensitive, literal wildcards) inside the scope', function (string $query, array $expected) {
    loginAs($this->w->a1);

    expect(quoteNumbers($query))->toBe($expected);
})->with([
    'whole number' => ['?q=QUO-FFFFF-GGGGG', ['QUO-FFFFF-GGGGG']],
    'lower case' => ['?q=quo-fffff-ggggg', ['QUO-FFFFF-GGGGG']],
    'without dashes' => ['?q=QUOFFFFFGGGGG', ['QUO-FFFFF-GGGGG']],
    'with spaces' => ['?q=+quo+fffff+', ['QUO-FFFFF-GGGGG']],
    'partial block' => ['?q=HHHHH', ['QUO-HHHHH-JJJJJ']],
    'PO case-insensitive' => ['?q=job+12%2Fb_7', ['QUO-BBBBB-CCCCC']],
    'PO underscore is literal' => ['?q=b_7', ['QUO-BBBBB-CCCCC']],
    'PO underscore does not match a letter' => ['?q=b-7', []],
    'PO percent is literal' => ['?q=50%25', ['QUO-DDDDD-EEEEE']],
    'percent alone matches only a PO with a percent' => ['?q=%25', ['QUO-DDDDD-EEEEE']],
    'underscore alone' => ['?q=_', ['QUO-DDDDD-EEEEE', 'QUO-BBBBB-CCCCC']],
    'backslash is literal' => ['?q=%5C', []],
    'the other company number' => ['?q=NNNNN', []],
    'the other company PO is only the shared one' => ['?q=Job', ['QUO-BBBBB-CCCCC']],
    'nothing matches' => ['?q=ZZZZZZ', []],
]);

it('keeps staff on all companies, honours company_id for staff and ignores it for customers', function () {
    $w = $this->w;

    loginAs($w->staff);
    expect(quoteNumbers('?sort=number'))->toBe(['QUO-BBBBB-CCCCC', 'QUO-DDDDD-EEEEE', 'QUO-FFFFF-GGGGG', 'QUO-HHHHH-JJJJJ', 'QUO-KKKKK-MMMMM', 'QUO-NNNNN-PPPPP'])
        ->and(quoteNumbers('?company_id='.$w->b->id))->toBe(['QUO-NNNNN-PPPPP'])
        ->and(quoteNumbers('?company_id=999999'))->toBe([])
        ->and(quoteNumbers('?q=Job+12'.'&sort=number'))->toBe(['QUO-BBBBB-CCCCC', 'QUO-NNNNN-PPPPP']);
    $this->getJson('/api/v1/quotes?company_id=abc')->assertStatus(422)->assertJsonValidationErrors('company_id');
    $this->getJson('/api/v1/quotes?company_id=0')->assertStatus(422);
    loginAs($w->admin);
    expect(quoteNumbers())->toHaveCount(6);

    loginAs($w->a1);
    expect(quoteNumbers('?company_id='.$w->b->id))->toHaveCount(5);
    $this->getJson('/api/v1/quotes?company_id=abc')->assertOk();
    loginAs($w->b1);
    expect(quoteNumbers('?q=Job'))->toBe(['QUO-NNNNN-PPPPP'])->and(quoteNumbers('?q=BBBBB'))->toBe([])->and(quoteNumbers('?status=submitted,in_review,quoted,expired&q=50'))->toBe([]);
});

it('sorts by the four allowed keys with the id as tie-break', function (string $sort, array $expected) {
    loginAs($this->w->a1);

    expect(quoteNumbers("?sort={$sort}"))->toBe($expected);
})->with([
    'created_at' => ['created_at', ['QUO-BBBBB-CCCCC', 'QUO-DDDDD-EEEEE', 'QUO-FFFFF-GGGGG', 'QUO-HHHHH-JJJJJ', 'QUO-KKKKK-MMMMM']],
    '-created_at' => ['-created_at', ['QUO-KKKKK-MMMMM', 'QUO-HHHHH-JJJJJ', 'QUO-FFFFF-GGGGG', 'QUO-DDDDD-EEEEE', 'QUO-BBBBB-CCCCC']],
    'number' => ['number', ['QUO-BBBBB-CCCCC', 'QUO-DDDDD-EEEEE', 'QUO-FFFFF-GGGGG', 'QUO-HHHHH-JJJJJ', 'QUO-KKKKK-MMMMM']],
    '-number' => ['-number', ['QUO-KKKKK-MMMMM', 'QUO-HHHHH-JJJJJ', 'QUO-FFFFF-GGGGG', 'QUO-DDDDD-EEEEE', 'QUO-BBBBB-CCCCC']],
]);

it('breaks created_at ties by id', function () {
    $a = QuoteRequest::factory()->forCompany($this->w->a, $this->w->a1)->createdAt('2026-12-01 10:00:00')->create();
    $b = QuoteRequest::factory()->forCompany($this->w->a, $this->w->a1)->createdAt('2026-12-01 10:00:00')->create();

    loginAs($this->w->a1);
    expect(array_slice(quoteNumbers('?sort=-created_at'), 0, 2))->toBe([$a->quote_number, $b->quote_number]);
});

it('paginates with exactly six meta keys, and a page beyond the last is an empty 200', function () {
    loginAs($this->w->a1);

    $page = $this->getJson('/api/v1/quotes?per_page=2&page=3')->assertOk();
    expect($page->json('meta'))->toBe(['current_page' => 3, 'last_page' => 3, 'per_page' => 2, 'total' => 5, 'from' => 5, 'to' => 5])->and($page->json('data'))->toHaveCount(1);

    $beyond = $this->getJson('/api/v1/quotes?per_page=2&page=9')->assertOk();
    expect($beyond->json('data'))->toBe([])->and($beyond->json('meta.from'))->toBeNull()->and($beyond->json('meta.to'))->toBeNull()->and($beyond->json('meta.total'))->toBe(5);
    $this->getJson('/api/v1/quotes?per_page=100')->assertOk();
    $this->getJson('/api/v1/quotes?page=10000')->assertOk();
});

it('answers invalid list parameters with 422 on that parameter, never 500', function (string $query, string $key) {
    loginAs($this->w->staff);

    $this->getJson('/api/v1/quotes'.$query)->assertStatus(422)->assertJsonValidationErrors($key);
})->with([
    'unknown status' => ['?status=bogus', 'status'], 'one bad status in a csv' => ['?status=quoted,bogus', 'status'], 'empty csv item' => ['?status=quoted,,expired', 'status'],
    'status array' => ['?status[]=quoted', 'status'], 'status with NUL' => ['?status=quoted%00,expired', 'status'], 'order status' => ['?status=pending', 'status'],
    'bad from' => ['?from=2026-13-40', 'from'], 'bad to' => ['?to=yesterday', 'to'], 'from after to' => ['?from=2026-12-02&to=2026-12-01', 'from'], 'datetime' => ['?from=2026-12-01T00:00:00Z', 'from'],
    'bad sort' => ['?sort=total', 'sort'], 'price sort is not offered' => ['?sort=-total', 'sort'], 'sort array' => ['?sort[]=number', 'sort'],
    'page zero' => ['?page=0', 'page'], 'page too high' => ['?page=10001', 'page'], 'page text' => ['?page=x', 'page'],
    'per_page zero' => ['?per_page=0', 'per_page'], 'per_page 101' => ['?per_page=101', 'per_page'], 'per_page float' => ['?per_page=1.5', 'per_page'],
    'q 33 chars' => ['?q='.'abcdefghijklmnopqrstuvwxyzabcdefg', 'q'], 'q with interior NUL' => ['?q=a%00b', 'q'], 'q array' => ['?q[]=a', 'q'], 'q invalid UTF-8' => ['?q=%FF', 'q'],
]);

it('trims leading and trailing NUL and spaces from q like any other parameter', function () {
    loginAs($this->w->a1);

    expect(quoteNumbers('?q=%00'))->toHaveCount(5)->and(quoteNumbers('?q=GGGGG%00'))->toBe(['QUO-FFFFF-GGGGG'])->and(quoteNumbers('?q=%20GGGGG%20'))->toBe(['QUO-FFFFF-GGGGG']);
});

it('rate limits quote creation to 10 per minute per user, counting rejected attempts, and never for staff', function () {
    $w = $this->w;
    loginAs($w->a1);

    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/quotes', ['lines' => []])->assertStatus(422);
    }
    $this->postJson('/api/v1/quotes', ['lines' => [['description' => 'x', 'quantity' => 1]]])->assertStatus(429)->assertHeader('Retry-After');
    expect(QuoteRequest::where('created_by_user_id', $w->a1->id)->count())->toBe(4);

    loginAs($w->a2)->postJson('/api/v1/quotes', ['lines' => [['description' => 'x', 'quantity' => 1]]])->assertCreated();

    loginAs($w->staff);
    foreach (range(1, 12) as $i) {
        $this->postJson('/api/v1/quotes', ['lines' => []])->assertForbidden();
    }

    $this->travel(61)->seconds();
    loginAs($w->a1)->postJson('/api/v1/quotes', ['lines' => [['description' => 'x', 'quantity' => 1]]])->assertCreated();
});

it('shares the api limiter of 60 requests per minute across the quote endpoints', function () {
    loginAs($this->w->a1);

    foreach (range(1, 60) as $i) {
        $this->getJson('/api/v1/quotes')->assertOk();
    }
    $this->getJson('/api/v1/quotes')->assertStatus(429)->assertHeader('Retry-After');
});

/** Number of queries of one request (the user's roles are cached on the user by the warm-up). */
function quoteQueryCount(string $method, string $uri, array $body = []): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->{$method}($uri, $body)->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('runs a constant number of queries for the list regardless of rows and per_page', function () {
    loginAs($this->w->a1);
    $this->getJson('/api/v1/quotes');
    $few = [quoteQueryCount('getJson', '/api/v1/quotes'), quoteQueryCount('getJson', '/api/v1/quotes?per_page=5'), quoteQueryCount('getJson', '/api/v1/quotes?q=Job&status=quoted,expired&from=2026-01-01')];

    QuoteRequest::factory()->count(25)->forCompany($this->w->a, $this->w->a1)->quoted()->create();
    $many = [quoteQueryCount('getJson', '/api/v1/quotes'), quoteQueryCount('getJson', '/api/v1/quotes?per_page=5'), quoteQueryCount('getJson', '/api/v1/quotes?q=Job&status=quoted,expired&from=2026-01-01')];

    expect($many)->toBe($few)->and(max($many))->toBeLessThanOrEqual(8)
        ->and(quoteQueryCount('getJson', '/api/v1/quotes?per_page=100'))->toBe(quoteQueryCount('getJson', '/api/v1/quotes?per_page=5'));

    loginAs($this->w->staff);
    $this->getJson('/api/v1/quotes');
    expect(quoteQueryCount('getJson', '/api/v1/quotes'))->toBeLessThanOrEqual(8);
});

it('runs a constant number of queries for the detail regardless of lines, history and attachments', function () {
    $w = $this->w;
    $small = QuoteRequest::factory()->forCompany($w->a, $w->a1)->withLines([['One line', 1, null]])->create();
    QuoteAttachment::factory()->create(['quote_request_id' => $small->id]);
    $large = QuoteRequest::factory()->forCompany($w->a, $w->a1)->accepted()
        ->withLines(array_map(fn ($i) => ["Line {$i}", 1, null], range(1, 20)))->create();
    QuoteAttachment::factory()->count(5)->create(['quote_request_id' => $large->id]);
    QuoteAttachment::factory()->byStaff($w->staff)->count(3)->create(['quote_request_id' => $large->id]);

    foreach ([$w->a1, $w->staff] as $viewer) {
        loginAs($viewer);
        $this->getJson("/api/v1/quotes/{$small->quote_number}");
        $one = quoteQueryCount('getJson', "/api/v1/quotes/{$small->quote_number}");
        $many = quoteQueryCount('getJson', "/api/v1/quotes/{$large->quote_number}");

        expect($many)->toBe($one)->and($many)->toBeLessThanOrEqual(11);
    }
});

it('inserts the same number of rows statements for 1 and 20 lines when creating a quote', function () {
    loginAs($this->w->a1);
    $inserts = function (int $lines) {
        $count = 0;
        DB::listen(function ($query) use (&$count) {
            str_starts_with(strtolower($query->sql), 'insert') && $count++;
        });
        $this->postJson('/api/v1/quotes', ['lines' => array_map(fn ($i) => ['description' => "Line {$i}", 'quantity' => 1], range(1, $lines))])->assertCreated();

        return $count;
    };
    $one = $inserts(1);

    expect($inserts(20))->toBe($one);
});
