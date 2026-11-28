<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LogCapture;

/** Point the default connection at a database that does not answer, for the duration of the callback. */
function secWithBrokenDatabase(Closure $fn): void
{
    config(['database.connections.sec_broken' => array_merge(config('database.connections.pgsql'), ['host' => '127.0.0.1', 'port' => 1, 'database' => 'nowhere', 'options' => [PDO::ATTR_TIMEOUT => 2]])]);
    config(['database.default' => 'sec_broken']);

    try {
        $fn();
    } finally {
        config(['database.default' => 'pgsql']);
        DB::purge('sec_broken');
    }
}

it('answers 200 {"status":"ok"} to a guest when the database and the cache work', function () {
    $this->getJson('/api/v1/health/ready')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('is public, scoped to nothing and limited by its own bucket of 120 a minute per address', function () {
    foreach (range(1, 120) as $i) {
        $this->getJson('/api/v1/health/ready')->assertOk();
    }

    $this->getJson('/api/v1/health/ready')->assertStatus(429)->assertHeader('Retry-After');
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->getJson('/api/v1/health/ready')->assertOk();
});

it('has a bucket separate from the guest api bucket, in both directions', function () {
    // 60 guest requests exhaust `api` for this address ...
    foreach (range(1, 60) as $i) {
        $this->getJson('/api/v1/health')->assertOk();
    }
    $this->getJson('/api/v1/health')->assertStatus(429);

    // ... the probe is not affected ...
    $this->getJson('/api/v1/health/ready')->assertOk();

    // ... and the probe does not use up the other buckets: a fresh address probes 119 times, then still has the rest of its guest bucket.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20']);
    foreach (range(1, 119) as $i) {
        $this->getJson('/api/v1/health/ready')->assertOk();
    }
    foreach (range(1, 59) as $i) {
        $this->getJson('/api/v1/health')->assertOk();
    }
    $this->postJson('/api/v1/auth/login', ['email' => 'nobody@nowhere.test', 'password' => 'wrong-password-1'])->assertStatus(422);
});

it('keeps the 429 of the probe generic', function () {
    foreach (range(1, 120) as $i) {
        $this->getJson('/api/v1/health/ready');
    }

    $response = $this->getJson('/api/v1/health/ready')->assertStatus(429);

    expect($response->json('message'))->toMatch('/^Too many attempts\. Please try again in \d+ seconds\.$/')->and($response->getContent())->not->toContain('database')->not->toContain('cache');
});

it('cannot be starved by /up: the framework route is unthrottled and uses no limiter bucket', function () {
    foreach (range(1, 130) as $i) {
        $this->get('/up')->assertOk();
    }

    $this->getJson('/api/v1/health/ready')->assertOk();
    $this->getJson('/api/v1/health')->assertOk();
});

it('answers 503 {"status":"unavailable"} when the database is down, without naming anything, and logs the failed check', function () {
    $logs = LogCapture::start();

    secWithBrokenDatabase(function () use (&$response) {
        $response = $this->getJson('/api/v1/health/ready');
    });

    expect($response->getStatusCode())->toBe(503)->and($response->getContent())->toBe('{"status":"unavailable"}')
        ->and($response->getContent())->not->toContain('database')->not->toContain('nowhere')->not->toContain('SQLSTATE')->not->toContain('127.0.0.1');
    $record = collect(LogCapture::records($logs))->firstWhere('message', 'Readiness check failed');
    expect($record['context'])->toBe(['check' => 'database'])->and($record['extra']['request_id'])->toBe($response->headers->get('X-Request-Id'));
});

it('logs a failing readiness check once a minute, however often the probe runs, but answers 503 every time', function () {
    $logs = LogCapture::start();
    $lines = fn () => count(array_filter(LogCapture::records($logs), fn ($r) => $r['message'] === 'Readiness check failed'));

    secWithBrokenDatabase(function () {
        foreach (range(1, 4) as $i) {
            $this->getJson('/api/v1/health/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
        }
    });
    expect($lines())->toBe(1);

    $this->travel(61)->seconds();
    secWithBrokenDatabase(fn () => $this->getJson('/api/v1/health/ready')->assertStatus(503));
    $this->travelBack();
    expect($lines())->toBe(2);
});

it('logs every failure when the cache cannot throttle the log line (fail open)', function () {
    $logs = LogCapture::start();
    Cache::shouldReceive('add')->andThrow(new RuntimeException('cache down'));

    secWithBrokenDatabase(function () {
        foreach (range(1, 3) as $i) {
            $this->getJson('/api/v1/health/ready')->assertStatus(503);
        }
    });

    expect(array_filter(LogCapture::records($logs), fn ($r) => $r['message'] === 'Readiness check failed'))->toHaveCount(3);
});

it('answers 503 when the cache cannot be written, with the same body, and never leaks the exception', function () {
    $logs = LogCapture::start();
    Cache::shouldReceive('put')->andThrow(new RuntimeException('connection to redis.internal:6379 refused, password=hunter2'));

    $response = $this->getJson('/api/v1/health/ready');

    expect($response->getStatusCode())->toBe(503)->and($response->getContent())->toBe('{"status":"unavailable"}');
    $record = collect(LogCapture::records($logs))->firstWhere('message', 'Readiness check failed');
    expect($record['context'])->toBe(['check' => 'cache'])->and(LogCapture::text($logs))->not->toContain('hunter2')->not->toContain('redis.internal');
});

it('answers 503 when the cache returns something else than it was given', function () {
    Cache::shouldReceive('put')->andReturnTrue();
    Cache::shouldReceive('get')->andReturn('stale');

    $this->getJson('/api/v1/health/ready')->assertStatus(503);
});

it('does not check the queue or the scheduler: a late cron never takes a node out of rotation', function () {
    Cache::forget('scheduler:heartbeat');
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => 1, 'created_at' => 1]);

    $this->getJson('/api/v1/health/ready')->assertOk();
});

it('keeps the liveness routes free of database access', function () {
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);
    $this->get('/up')->assertOk();

    expect($queries)->toBe(0);
});

it('reports a healthy system from the CLI and exits 0 (non strict ignores the scheduler and the failed jobs)', function () {
    $code = Artisan::call('portal:health', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($code)->toBe(0)->and($report['healthy'])->toBeTrue()
        ->and(array_keys($report['checks']))->toBe(['database', 'migrations', 'cache', 'storage', 'failed_jobs', 'oldest_pending_job', 'scheduler_heartbeat'])
        ->and($report['checks']['migrations']['detail'])->toBe('none pending')->and($report['checks']['oldest_pending_job']['detail'])->toBe('queue empty');
});

it('fails --strict without a scheduler heartbeat, passes with a fresh one, fails again when it is older than 3 minutes', function () {
    expect(Artisan::call('portal:health', ['--strict' => true]))->toBe(1);

    Cache::put('scheduler:heartbeat', now()->timestamp, 3600);
    expect(Artisan::call('portal:health', ['--strict' => true]))->toBe(0);

    Cache::put('scheduler:heartbeat', now()->subSeconds(181)->timestamp, 3600);
    expect(Artisan::call('portal:health', ['--strict' => true]))->toBe(1);

    Cache::put('scheduler:heartbeat', now()->subSeconds(170)->timestamp, 3600);
    expect(Artisan::call('portal:health', ['--strict' => true]))->toBe(0);
});

it('counts failed jobs and fails --strict only', function () {
    Cache::put('scheduler:heartbeat', now()->timestamp, 3600);
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

    expect(Artisan::call('portal:health'))->toBe(0);
    expect(Artisan::call('portal:health', ['--strict' => true, '--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['checks']['failed_jobs'])->toBe(['ok' => false, 'detail' => '1']);
});

it('reports the age of the oldest waiting job', function () {
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time() - 600, 'created_at' => time() - 600]);

    Artisan::call('portal:health', ['--json' => true]);
    $age = (int) json_decode(Artisan::output(), true)['checks']['oldest_pending_job']['detail'];

    expect($age)->toBeGreaterThanOrEqual(599)->toBeLessThan(700);
});

it('exits 1 with pending migrations', function () {
    DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

    expect(Artisan::call('portal:health', ['--json' => true]))->toBe(1);
    $report = json_decode(Artisan::output(), true);
    expect($report['healthy'])->toBeFalse()->and($report['checks']['migrations'])->toMatchArray(['ok' => false]);
});

it('exits 1 when the database is unreachable and says so in plain text', function () {
    secWithBrokenDatabase(function () use (&$code) {
        $code = Artisan::call('portal:health');
    });

    expect($code)->toBe(1)->and(Artisan::output())->toContain('database')->toContain('FAIL')->toContain('Unhealthy.');
});
