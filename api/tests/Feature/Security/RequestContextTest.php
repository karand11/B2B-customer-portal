<?php

use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\LogCapture;

beforeEach(function () {
    Route::get('/api/v1/_sec_log', function () {
        Log::info('inside the request');

        return response()->json(['ok' => true]);
    });
    Route::middleware(['api', 'auth:sanctum', 'active'])->get('/api/v1/_sec_log_user', function () {
        Log::info('inside an authenticated request');

        return response()->json(['ok' => true]);
    });
});

it('puts the request id on every log record of the request and on the response', function () {
    $logs = LogCapture::start();

    $response = $this->getJson('/api/v1/_sec_log', ['X-Request-Id' => 'trace-abcdef-0001'])->assertOk();

    $records = array_values(array_filter(LogCapture::records($logs), fn ($r) => $r['message'] === 'inside the request'));
    expect($response->headers->get('X-Request-Id'))->toBe('trace-abcdef-0001')
        ->and($records)->toHaveCount(1)->and($records[0]['extra']['request_id'])->toBe('trace-abcdef-0001')
        ->and($records[0]['extra'])->not->toHaveKey('user_id');
});

it('replaces an unsafe inbound id with a UUID in the log and on the response', function () {
    $logs = LogCapture::start();

    $response = $this->getJson('/api/v1/_sec_log', ['X-Request-Id' => "bad id\twith spaces"]);
    $record = collect(LogCapture::records($logs))->firstWhere('message', 'inside the request');

    expect($response->headers->get('X-Request-Id'))->toMatch(UUID_V4)->and($record['extra']['request_id'])->toBe($response->headers->get('X-Request-Id'))
        ->and(LogCapture::text($logs))->not->toContain('with spaces');
});

it('gives every request its own id, so a request never inherits the previous request\'s context', function () {
    $user = makeUser('customer');
    $this->actingAs($user)->getJson('/api/v1/_sec_log_user')->assertOk();
    $first = Context::get('request_id');
    $this->app['auth']->forgetGuards();

    $logs = LogCapture::start();
    $this->getJson('/api/v1/_sec_log')->assertOk();
    $record = collect(LogCapture::records($logs))->firstWhere('message', 'inside the request');

    expect($record['extra']['request_id'])->not->toBe($first)->and($record['extra'])->not->toHaveKey('user_id');
});

it('adds the user id, and only the id, once the user is known', function () {
    $user = makeUser('customer');
    $logs = LogCapture::start();

    $this->actingAs($user)->getJson('/api/v1/_sec_log_user')->assertOk();

    $record = collect(LogCapture::records($logs))->firstWhere('message', 'inside an authenticated request');
    expect($record['extra']['user_id'])->toBe($user->id)->and(array_keys($record['extra']))->toEqualCanonicalizing(['request_id', 'user_id'])
        ->and(LogCapture::text($logs))->not->toContain($user->email)->not->toContain($user->name);
});

it('carries the request id into a queued job and into the log lines the job writes (Context is dehydrated into the payload)', function () {
    config(['queue.default' => 'database']);
    Route::get('/api/v1/_sec_queue', function () {
        dispatch(function () {
            Log::info('inside the job');
        });

        return response()->json(['queued' => true]);
    });

    $response = $this->getJson('/api/v1/_sec_queue', ['X-Request-Id' => 'trace-queue-0001'])->assertOk();

    $payload = json_decode(DB::table('jobs')->value('payload'), true);
    // Context stores its values serialized inside the payload.
    expect(unserialize($payload['illuminate:log:context']['data']['request_id']))->toBe('trace-queue-0001')
        ->and($response->headers->get('X-Request-Id'))->toBe('trace-queue-0001');

    // A worker is another process: start it with an empty context and let it read the payload.
    Context::flush();
    $logs = LogCapture::start();
    Artisan::call('queue:work', ['--once' => true, '--tries' => 1]);

    $record = collect(LogCapture::records($logs))->firstWhere('message', 'inside the job');
    expect($record)->not->toBeNull()->and($record['extra']['request_id'])->toBe('trace-queue-0001');
});

it('logs a failed job with its class and queue only, never the exception message', function () {
    config(['queue.default' => 'database']);
    Route::get('/api/v1/_sec_queue_fail', function () {
        dispatch(function () {
            throw new RuntimeException('Mail to secret.person@secret.test failed');
        });

        return response()->json(['queued' => true]);
    });
    $this->getJson('/api/v1/_sec_queue_fail', ['X-Request-Id' => 'trace-fail-0001'])->assertOk();
    Context::flush();
    $logs = LogCapture::start();

    Artisan::call('queue:work', ['--once' => true, '--tries' => 1]);

    $failed = collect(LogCapture::records($logs))->firstWhere('message', 'Queue job failed');
    expect($failed)->not->toBeNull()
        ->and(array_keys($failed['context']))->toEqualCanonicalizing(['job', 'queue', 'attempts', 'exception'])
        ->and($failed['context']['exception'])->toBe(RuntimeException::class)->and($failed['context']['queue'])->toBe('default')
        ->and($failed['extra']['request_id'])->toBe('trace-fail-0001')
        ->and(LogCapture::text($logs))->not->toContain('secret.person');
});

it('logs the queue backlog with the queue and size only', function () {
    $logs = LogCapture::start();

    event(new QueueBusy('database', 'default', 150));

    $record = collect(LogCapture::records($logs))->firstWhere('message', 'Queue backlog');
    expect($record['context'])->toBe(['queue' => 'default', 'size' => 150]);
});

it('logs slow queries with the SQL and its placeholders, never the bound values', function () {
    config(['portal.slow_query_ms' => 5]);
    $logs = LogCapture::start();

    DB::select('select ? as secret, pg_sleep(0.05)', ['secret-bound-value-xyz']);

    $record = collect(LogCapture::records($logs))->firstWhere('message', 'Slow query');
    expect($record)->not->toBeNull()
        ->and(array_keys($record['context']))->toBe(['ms', 'sql', 'connection'])
        ->and($record['context']['sql'])->toBe('select ? as secret, pg_sleep(0.05)')->and($record['context']['ms'])->toBeGreaterThan(40)
        ->and(LogCapture::text($logs))->not->toContain('secret-bound-value-xyz');
});

it('does not log fast queries, and the threshold 0 switches the slow query log off', function () {
    $logs = LogCapture::start();
    DB::select('select 1');
    config(['portal.slow_query_ms' => 0]);
    DB::select('select pg_sleep(0.02)');

    expect(array_column(LogCapture::records($logs), 'message'))->not->toContain('Slow query');
});

it('declares the structured log channels: JSON lines with the Context fields', function () {
    foreach (['stderr_json', 'daily_json'] as $channel) {
        expect(config("logging.channels.{$channel}"))->not->toBeNull($channel);
    }
    $line = (new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true))->format(new LogRecord(
        new DateTimeImmutable, 'portal', Level::Warning, 'Slow query', ['ms' => 12], ['request_id' => 'abc12345', 'user_id' => 7],
    ));
    $decoded = json_decode($line, true);

    expect(str_ends_with($line, "\n"))->toBeTrue()->and($decoded['message'])->toBe('Slow query')->and($decoded['extra'])->toBe(['request_id' => 'abc12345', 'user_id' => 7])
        ->and(config('logging.channels.daily_json.formatter'))->toBe(JsonFormatter::class)->and(config('logging.channels.stderr_json.formatter'))->toBe(JsonFormatter::class);
});
