<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Support\LogCapture;
use Tests\Support\ProductionConfig;

it('answers every request with a generic 500 and ONE log line naming the failed rules while production config is unsafe', function (string $env) {
    ProductionConfig::apply($env, ['app.debug' => true, 'session.secure' => false]);
    $logs = LogCapture::start();

    $response = $this->getJson('https://'.ProductionConfig::HOST.'/api/v1/health');

    expect($response->getStatusCode())->toBe(500)->and($response->getContent())->toBe('{"message":"Server Error"}')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')->and($response->headers->get('X-Request-Id'))->not->toBeNull();
    $records = array_values(array_filter(LogCapture::records($logs), fn ($r) => str_starts_with($r['message'], 'Unsafe production configuration')));
    expect($records)->toHaveCount(1)->and($records[0]['level'])->toBe('CRITICAL')->and($records[0]['context'])->toBe(['rules' => ['APP_DEBUG', 'SESSION_SECURE_COOKIE']])
        ->and($records[0]['extra']['request_id'])->toBe($response->headers->get('X-Request-Id'));
})->with(['production', 'staging']);

it('also guards the names that used to bypass it: prod, live, Production, PRODUCTION, an empty name', function (string $env) {
    ProductionConfig::apply($env, ['app.debug' => true]);

    $this->getJson('https://'.ProductionConfig::HOST.'/api/v1/health')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);

    expect(Artisan::call('portal:check-config', ['--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true))->toMatchArray(['environment' => $env, 'enforced' => true, 'ok' => false]);
})->with(['prod', 'live', 'Production', 'PRODUCTION', 'development', '']);

it('blocks every route, the framework health route and the SPA login included', function () {
    ProductionConfig::apply('production', ['portal.trusted_hosts' => []]);

    foreach (['/up', '/api/v1/health', '/api/v1/health/ready', '/sanctum/csrf-cookie'] as $uri) {
        $this->getJson('https://'.ProductionConfig::HOST.$uri)->assertStatus(500);
    }
    $this->postJson('https://'.ProductionConfig::HOST.'/api/v1/auth/login', ['email' => 'a@b.test', 'password' => 'x'])->assertStatus(500);
});

it('serves normally with a safe configuration whatever the production name is', function (string $env) {
    ProductionConfig::apply($env);

    $this->getJson('https://'.ProductionConfig::HOST.'/api/v1/health')->assertOk();
    $this->getJson('https://'.ProductionConfig::HOST.'/api/v1/health/ready')->assertOk();
})->with(['production', 'prod', 'Live', '']);

it('is inert in local, testing and loadtest (any case), whatever the config says', function (string $env) {
    ProductionConfig::apply($env, ['app.debug' => true, 'session.secure' => false, 'portal.trusted_hosts' => [], 'portal.trusted_proxies' => []]);

    $this->getJson('/api/v1/health')->assertOk();
    expect(Artisan::call('portal:check-config', ['--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true))->toMatchArray(['environment' => $env, 'enforced' => false, 'ok' => true]);
})->with(['local', 'testing', 'loadtest', 'LOCAL', ' Testing ']);

it('does not block console commands (image builds run artisan)', function () {
    ProductionConfig::apply('production', ['app.debug' => true]);

    expect(Artisan::call('list'))->toBe(0)->and(Artisan::call('about', ['--only' => 'environment']))->toBe(0);
});

it('makes `portal:check-config` fail in production and staging with the rule names and no values', function (string $env) {
    ProductionConfig::apply($env, ['app.url' => 'http://secret-host.example', 'app.debug' => true, 'portal.trusted_hosts' => []]);

    $code = Artisan::call('portal:check-config');
    $output = Artisan::output();

    expect($code)->toBe(1)->and($output)->toContain('UNSAFE: APP_DEBUG')->toContain('UNSAFE: APP_URL')->toContain('UNSAFE: TRUSTED_HOSTS')
        ->toContain('Configuration check failed')->not->toContain('secret-host');
})->with(['production', 'staging']);

it('passes `portal:check-config` for a safe configuration, and --strict turns warnings into a failure', function () {
    ProductionConfig::apply('production', ['queue.default' => 'sync']);

    expect(Artisan::call('portal:check-config'))->toBe(0);
    expect(Artisan::output())->toContain('WARNING: QUEUE_CONNECTION')->toContain('Configuration check passed');
    expect(Artisan::call('portal:check-config', ['--strict' => true]))->toBe(1);

    ProductionConfig::apply('production');
    config(['invoicing.seller.name' => 'Real Seller GmbH']);
    expect(Artisan::call('portal:check-config', ['--strict' => true]))->toBe(0);
});

it('treats an empty TRUSTED_PROXIES as UNSAFE (exit 1, HTTP 500) unless TRUSTED_PROXIES_NONE=true acknowledges that clients connect directly', function () {
    ProductionConfig::apply('production', ['portal.trusted_proxies' => []]);

    expect(Artisan::call('portal:check-config'))->toBe(1);
    expect(Artisan::output())->toContain('UNSAFE: TRUSTED_PROXIES')->toContain('Configuration check failed');
    $this->getJson('https://'.ProductionConfig::HOST.'/api/v1/health')->assertStatus(500);

    config(['portal.trusted_proxies_none' => true]);

    expect(Artisan::call('portal:check-config', ['--strict' => true]))->toBe(0);
    $this->getJson('https://'.ProductionConfig::HOST.'/api/v1/health')->assertOk();
});

it('does not require TRUSTED_PROXIES in local, testing and loadtest', function (string $env) {
    ProductionConfig::apply($env, ['portal.trusted_proxies' => [], 'portal.trusted_hosts' => []]);

    expect(Artisan::call('portal:check-config'))->toBe(0);
    $this->getJson('/api/v1/health')->assertOk();
})->with(['local', 'testing', 'loadtest']);

it('reports but never fails in local, testing and loadtest', function () {
    config(['app.debug' => true]);

    expect(Artisan::call('portal:check-config'))->toBe(0);
    expect(Artisan::output())->toContain('every environment except local, testing and loadtest')->toContain('APP_DEBUG');
});

it('prints machine readable output with --json', function () {
    ProductionConfig::apply('production', ['app.debug' => true]);

    $code = Artisan::call('portal:check-config', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($code)->toBe(1)->and($report)->toMatchArray(['environment' => 'production', 'enforced' => true, 'ok' => false])->and($report['errors'])->toBe(['APP_DEBUG']);
});

it('keeps the --json shape: exactly environment, enforced, ok, errors and warnings, rule names only', function () {
    ProductionConfig::apply('production', ['portal.trusted_proxies' => [], 'app.url' => 'http://secret-host.example']);

    Artisan::call('portal:check-config', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect(array_keys($report))->toBe(['environment', 'enforced', 'ok', 'errors', 'warnings'])
        ->and($report['errors'])->toBe(['APP_URL', 'TRUSTED_PROXIES'])->and($report['warnings'])->toBe([])
        ->and(json_encode($report))->not->toContain('secret-host');

    config(['portal.trusted_proxies_none' => true, 'app.url' => 'https://'.ProductionConfig::HOST]);
    Artisan::call('portal:check-config', ['--json' => true]);

    expect(json_decode(Artisan::output(), true))->toBe(['environment' => 'production', 'enforced' => true, 'ok' => true, 'errors' => [], 'warnings' => []]);
});

it('reports an invalid TRUSTED_HOSTS list as the rule TRUSTED_HOSTS (the boot-time parser leaves it empty for portal:check-config)', function () {
    ProductionConfig::apply('production', ['portal.trusted_hosts' => []]);

    expect(Artisan::call('portal:check-config', ['--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['errors'])->toBe(['TRUSTED_HOSTS']);
});

it('writes ONE critical line per rule set and minute, however many requests are refused', function () {
    ProductionConfig::apply('production', ['app.debug' => true]);
    $logs = LogCapture::start();
    $lines = fn () => count(array_filter(LogCapture::records($logs), fn ($r) => str_starts_with($r['message'], 'Unsafe production configuration')));

    foreach (range(1, 5) as $i) {
        $this->getJson('https://'.ProductionConfig::HOST.'/api/v1/health')->assertStatus(500);
    }
    expect($lines())->toBe(1);

    // A different rule set is a different line.
    config(['session.secure' => false]);
    $this->getJson('https://'.ProductionConfig::HOST.'/api/v1/health')->assertStatus(500);
    expect($lines())->toBe(2);

    $this->travel(61)->seconds();
    $this->getJson('https://'.ProductionConfig::HOST.'/api/v1/health')->assertStatus(500);
    expect($lines())->toBe(3);
    $this->travelBack();
});

it('fails open when the cache cannot throttle the log line: the line is written every time', function () {
    ProductionConfig::apply('production', ['app.debug' => true]);
    $logs = LogCapture::start();
    Cache::shouldReceive('add')->andThrow(new RuntimeException('cache down'));

    foreach (range(1, 3) as $i) {
        $this->getJson('https://'.ProductionConfig::HOST.'/api/v1/health')->assertStatus(500);
    }

    expect(array_filter(LogCapture::records($logs), fn ($r) => str_starts_with($r['message'], 'Unsafe production configuration')))->toHaveCount(3);
});
