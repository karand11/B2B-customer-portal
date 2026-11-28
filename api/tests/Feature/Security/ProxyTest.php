<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\ProductionConfig;

afterEach(fn () => Request::setTrustedHosts([]));

beforeEach(function () {
    // What the application sees of the request, after the global middleware ran.
    Route::get('/api/v1/_sec_seen', fn (Request $r) => response()->json(['ip' => $r->ip(), 'secure' => $r->isSecure(), 'host' => $r->getHost(), 'scheme' => $r->getScheme()]));
});

/** The request as the application sees it, sent from TCP peer $peer with these forwarding headers. */
function secSeen(string $peer, array $headers = [], string $url = 'http://localhost/api/v1/_sec_seen'): array
{
    return test()->withServerVariables(['REMOTE_ADDR' => $peer])->withHeaders($headers)->getJson($url)->assertOk()->json();
}

it('trusts nobody by default: a spoofed X-Forwarded-For from any peer changes nothing', function () {
    expect(config('portal.trusted_proxies'))->toBe([]);

    $seen = secSeen('198.51.100.20', ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.test', 'X-Forwarded-Port' => '443']);

    expect($seen)->toMatchArray(['ip' => '198.51.100.20', 'secure' => false, 'scheme' => 'http'])->and($seen['host'])->not->toBe('evil.test');
});

it('uses the forwarded client address only from a listed proxy', function () {
    config(['portal.trusted_proxies' => ['10.0.0.0/8', '192.0.2.1']]);

    expect(secSeen('10.1.2.3', ['X-Forwarded-For' => '203.0.113.7'])['ip'])->toBe('203.0.113.7')
        ->and(secSeen('192.0.2.1', ['X-Forwarded-For' => '203.0.113.8'])['ip'])->toBe('203.0.113.8')
        ->and(secSeen('192.0.2.2', ['X-Forwarded-For' => '203.0.113.9'])['ip'])->toBe('192.0.2.2')
        ->and(secSeen('11.0.0.1', ['X-Forwarded-For' => '203.0.113.9'])['ip'])->toBe('11.0.0.1');
});

it('takes the right-most address a trusted proxy chain did not add, so a client-supplied leftmost value never wins', function () {
    config(['portal.trusted_proxies' => ['10.0.0.5', '10.0.0.6']]);

    $seen = secSeen('10.0.0.6', ['X-Forwarded-For' => '1.2.3.4, 203.0.113.7, 10.0.0.5']);

    expect($seen['ip'])->toBe('203.0.113.7');
});

it('honours X-Forwarded-Proto and Port from a listed proxy only, and never X-Forwarded-Host or Prefix', function () {
    config(['portal.trusted_proxies' => ['10.0.0.5']]);

    $trusted = secSeen('10.0.0.5', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.test', 'X-Forwarded-Prefix' => '/evil']);
    $untrusted = secSeen('10.0.0.9', ['X-Forwarded-Proto' => 'https']);

    expect($trusted)->toMatchArray(['secure' => true, 'scheme' => 'https'])->and($trusted['host'])->not->toBe('evil.test')
        ->and($untrusted['secure'])->toBeFalse();
});

it('keys the per-IP limiters on the address the app sees: spoofing from an untrusted peer does not make new buckets', function () {
    foreach (range(1, 20) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->withHeaders(['X-Forwarded-For' => "203.0.113.{$i}"])
            ->postJson('/api/v1/auth/login', ['email' => "ghost{$i}@nowhere.test", 'password' => 'wrong-password-1'])->assertStatus(422);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->withHeaders(['X-Forwarded-For' => '203.0.113.99'])
        ->postJson('/api/v1/auth/login', ['email' => 'ghost99@nowhere.test', 'password' => 'wrong-password-1'])->assertStatus(429);
});

it('gives every client behind a trusted proxy its own bucket, and one client one bucket', function () {
    config(['portal.trusted_proxies' => ['198.51.100.20']]);

    foreach (range(1, 25) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->withHeaders(['X-Forwarded-For' => "203.0.113.{$i}"])
            ->postJson('/api/v1/auth/login', ['email' => "ghost{$i}@nowhere.test", 'password' => 'wrong-password-1'])->assertStatus(422);
    }

    foreach (range(1, 20) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->withHeaders(['X-Forwarded-For' => '203.0.113.200'])
            ->postJson('/api/v1/auth/login', ['email' => "other{$i}@nowhere.test", 'password' => 'wrong-password-1'])->assertStatus(422);
    }
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->withHeaders(['X-Forwarded-For' => '203.0.113.200'])
        ->postJson('/api/v1/auth/login', ['email' => 'other21@nowhere.test', 'password' => 'wrong-password-1'])->assertStatus(429);
});

it('answers a Host outside TRUSTED_HOSTS with a generic 400 that never echoes the host', function (string $host) {
    config(['portal.trusted_hosts' => ['api.portal.example']]);

    $response = $this->getJson("http://{$host}/api/v1/health");

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getContent())->toBe('{"message":"Bad Request."}')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Request-Id'))->not->toBeNull();
})->with(['evil.test', 'api.portal.example.evil.test', 'sub.api.portal.example', 'API.PORTAL.EXAMPLE.evil.test', 'evil.test:8080']);

it('answers the same 400 for web and framework routes (up, the CSRF cookie) and for a forwarded host', function () {
    config(['portal.trusted_hosts' => ['api.portal.example'], 'portal.trusted_proxies' => ['10.0.0.5']]);

    $this->get('http://evil.test/up')->assertStatus(400);
    $this->get('http://evil.test/sanctum/csrf-cookie')->assertStatus(400);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeaders(['X-Forwarded-Host' => 'api.portal.example'])->getJson('http://evil.test/api/v1/health')->assertStatus(400);
});

it('accepts the listed hosts (case-insensitive) and every host when the list is empty', function () {
    config(['portal.trusted_hosts' => ['api.portal.example', 'api2.portal.example']]);

    $this->getJson('http://api.portal.example/api/v1/health')->assertOk();
    $this->getJson('http://api2.portal.example/api/v1/health')->assertOk();
    $this->getJson('http://API.Portal.Example/api/v1/health')->assertOk();

    config(['portal.trusted_hosts' => []]);
    $this->getJson('http://anything.test/api/v1/health')->assertOk();
});

it('does not let a trusted host list of one request leak into the next test (static state is reset on every request)', function () {
    expect(config('portal.trusted_hosts'))->toBe([]);
    $this->getJson('http://leak-check.test/api/v1/health')->assertOk();
});

it('answers a foreign Host in production with the 400, not with a 500 or a redirect', function () {
    ProductionConfig::apply();

    $this->getJson('https://evil.test/api/v1/health')->assertStatus(400)->assertExactJson(['message' => 'Bad Request.']);
    $this->getJson('https://'.ProductionConfig::HOST.'/api/v1/health')->assertOk();
});
