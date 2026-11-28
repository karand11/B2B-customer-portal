<?php

use App\Support\ReportedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\LogCapture;

/** A function that recurses $depth times before it throws: a trace deeper than the cap. */
function secDeepThrow(int $depth): never
{
    $depth > 0 && secDeepThrow($depth - 1);

    throw new RuntimeException('deep failure for deep.person@secret.test');
}

beforeEach(function () {
    Route::get('/api/v1/_sec_throw', function () {
        throw new RuntimeException('Cannot mail outer.person@secret.test', 0, new LogicException('transport said: inner.person@secret.test refused', 0, new DomainException('root cause for root.person@secret.test')));
    });
    Route::get('/api/v1/_sec_deep', fn () => secDeepThrow(60));
    Route::get('/api/v1/_sec_db', fn () => DB::transaction(fn () => DB::table('orders')->insert([
        'order_number' => 'ORD-BCDFG-HJKMP', 'company_id' => 1, 'created_by_user_id' => 1, 'status' => 'pending', 'currency' => 'zz', 'po_reference' => 'SECRET-PO-123',
        'subtotal_cents' => 0, 'total_cents' => 0, 'created_at' => now(), 'updated_at' => now(),
    ])));
});

it('logs the masked message, the trace as frames, the previous exceptions and the request id, and never the raw address', function () {
    $logs = LogCapture::start();

    $response = $this->getJson('/api/v1/_sec_throw', ['X-Request-Id' => 'trace-exc-0001']);

    expect($response->getStatusCode())->toBe(500)->and($response->getContent())->not->toContain('secret.test');
    $record = collect(LogCapture::records($logs))->firstWhere('message', RuntimeException::class);
    expect($record)->not->toBeNull()->and($record['level'])->toBe('ERROR')
        ->and($record['context']['message'])->toBe('Cannot mail [email]')
        ->and($record['context']['at'])->toMatch('#^tests/Feature/Security/ExceptionReportTest\.php:\d+$#')
        ->and($record['context']['trace'])->not->toBeEmpty()->and(count($record['context']['trace']))->toBeLessThanOrEqual(25)
        ->and($record['extra']['request_id'])->toBe('trace-exc-0001');
    foreach ($record['context']['trace'] as $frame) {
        expect($frame)->toMatch('#^(\[internal\]|[^\s:]+:\d+) \S*$#');
    }
    // The chain: the cause and its cause, each with a masked message and a location, no raw address anywhere.
    expect($record['context']['previous'])->toHaveCount(2)
        ->and($record['context']['previous'][0])->toMatchArray(['class' => LogicException::class, 'message' => 'transport said: [email] refused'])
        ->and($record['context']['previous'][1])->toMatchArray(['class' => DomainException::class, 'message' => 'root cause for [email]'])
        ->and(LogCapture::text($logs))->not->toContain('secret.test')->not->toContain('outer.person')->not->toContain('inner.person')->not->toContain('root.person');
});

it('keeps paths relative to the application root and shows frames of the code that failed', function () {
    $logs = LogCapture::start();

    $this->getJson('/api/v1/_sec_throw')->assertStatus(500);

    $text = LogCapture::text($logs);
    $record = collect(LogCapture::records($logs))->firstWhere('message', RuntimeException::class);
    expect(str_replace('\\/', '/', $text))->not->toContain(str_replace('\\', '/', base_path()).'/')
        ->and(implode("\n", $record['context']['trace']))->toContain('vendor/laravel/framework/src/Illuminate/Routing/');
});

it('caps the trace at 25 frames and logs no argument or object', function () {
    $logs = LogCapture::start();

    $this->getJson('/api/v1/_sec_deep')->assertStatus(500);

    $record = collect(LogCapture::records($logs))->firstWhere('message', RuntimeException::class);
    expect($record['context']['trace'])->toHaveCount(ReportedException::MAX_FRAMES)->and($record['context']['trace'][0])->toContain('secDeepThrow')
        ->and(json_encode($record['context']))->not->toContain('deep.person')->and($record['context']['message'])->toBe('deep failure for [email]');
});

it('masks every address form of the message and cuts a long one', function () {
    expect(ReportedException::mask('a.b+tag@sub.example.co.uk and X_Y@Example.COM; <z@z.io>'))->toBe('[email] and [email]; <[email]>')
        ->and(ReportedException::mask('no address here'))->toBe('no address here')
        ->and(mb_strlen(ReportedException::mask(str_repeat('x', 1000))))->toBe(300);
});

it('still logs a database failure as SQLSTATE and placeholder SQL only: no message, no bound value, no trace of values', function () {
    $logs = LogCapture::start();

    $this->getJson('/api/v1/_sec_db')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);

    $record = collect(LogCapture::records($logs))->firstWhere('message', 'Database query failed.');
    expect($record)->not->toBeNull()->and(array_keys($record['context']))->toBe(['sqlstate', 'sql', 'connection', 'code'])
        ->and($record['context']['sql'])->toContain('insert into "orders"')->and(LogCapture::text($logs))->not->toContain('SECRET-PO-123');
});

it('never logs the message of a database exception wrapped in another exception, only its class', function () {
    $secret = new PDOException('SQLSTATE[23514]: Failing row contains (SECRET-PO-123, someone@secret.test)');
    $wrapped = new RuntimeException('Import failed for wrapped.person@secret.test', 0, $secret);

    $context = ReportedException::context($wrapped);

    expect($context['previous'][0])->toMatchArray(['class' => PDOException::class, 'message' => '[database message omitted]'])
        ->and(json_encode($context))->not->toContain('SECRET-PO-123')->not->toContain('secret.test');
});
