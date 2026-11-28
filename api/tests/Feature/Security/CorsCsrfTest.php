<?php

use Database\Seeders\DemoSeeder;
use Tests\Support\CsrfEnforced;
use Tests\Support\RouteMatrix;

const SEC_SPA = 'http://localhost:5173';

function secPreflight(string $origin, string $method = 'POST', string $headers = 'content-type,x-xsrf-token', string $uri = '/api/v1/auth/login')
{
    return test()->withHeaders(['Origin' => $origin, 'Access-Control-Request-Method' => $method, 'Access-Control-Request-Headers' => $headers])->options($uri);
}

it('answers a preflight from the SPA origin with its exact origin and credentials', function () {
    $response = secPreflight(SEC_SPA);

    expect($response->getStatusCode())->toBe(204)
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe(SEC_SPA)
        ->and($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true')
        ->and($response->headers->get('Access-Control-Allow-Methods'))->toContain('POST')
        ->and($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('*');
});

it('never answers Access-Control-Allow-Origin to a foreign, null or look-alike origin', function (string $origin) {
    $response = secPreflight($origin);

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse()->and($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
})->with(['http://evil.test', 'null', 'http://localhost:5173.evil.test', 'http://localhost:5174', 'https://localhost:5173', 'http://localhost', 'http://evil.test/http://localhost:5173', 'HTTP://LOCALHOST:5173']);

it('lists only the methods and headers the API uses in a preflight answer (never a wildcard)', function (string $method, string $headers) {
    $response = secPreflight(SEC_SPA, $method, $headers);

    // The preflight answer lists exactly what the API allows; the browser blocks the real request when its method or header is not in the list.
    $allowedMethods = array_map('trim', explode(',', (string) $response->headers->get('Access-Control-Allow-Methods')));
    $allowedHeaders = array_map('trim', explode(',', strtolower((string) $response->headers->get('Access-Control-Allow-Headers'))));
    expect($allowedMethods)->toBe(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])->and($allowedHeaders)->toBe(['accept', 'content-type', 'x-requested-with', 'x-xsrf-token'])
        ->and(in_array($method, $allowedMethods, true) && in_array(strtolower($headers), $allowedHeaders, true))->toBe($method === 'POST' && $headers === 'content-type');
})->with([['TRACE', 'content-type'], ['PROPFIND', 'content-type'], ['POST', 'x-evil-header'], ['POST', 'authorization']]);

it('does not put CORS headers on a simple request from a foreign origin', function () {
    $response = $this->withHeaders(['Origin' => 'http://evil.test'])->getJson('/api/v1/health');

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

it('treats a request from a foreign origin as a guest even with a valid session cookie (no cookie auth for non-stateful origins)', function () {
    $this->seed(DemoSeeder::class);
    $login = $this->postJson('/api/v1/auth/login', ['email' => 'alice@northwind.test', 'password' => TEST_PASSWORD])->assertOk();
    carrySession($login);
    // In production every request is a new process: the guard must not remember the user the login just set (that would be a test artefact).
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/me')->assertOk();
    $this->app['auth']->forgetGuards();

    foreach (['http://evil.test', 'null'] as $origin) {
        $this->withHeaders(['Origin' => $origin]);
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->postJson('/api/v1/orders', [])->assertUnauthorized();
    }

});

it('has no 2xx for a write from a foreign origin on any route that needs a user', function () {
    $user = makeUser('admin');
    $this->actingAs($user);

    foreach (RouteMatrix::routes() as $key => [$method, $uri]) {
        // Logout needs a session by nature (it ends it): a request without one cannot reach it in production.
        if ($method === 'GET' || RouteMatrix::ROUTES[$key] === 'public' || $key === 'POST api/v1/auth/logout') {
            continue;
        }
        $this->withHeaders(['Origin' => 'http://evil.test']);
        // actingAs sets the user on the guard directly; a non-stateful request must not rely on a session it does not have.
        $status = $this->json($method, RouteMatrix::url($uri), [])->getStatusCode();
        expect($status)->not->toBeLessThan(400, "{$key} answered {$status} to a foreign origin");
    }
});

it('returns 419 for every non-GET route without a CSRF token from a stateful browser, public routes included', function () {
    CsrfEnforced::enable();
    $user = makeUser('admin');
    $checked = 0;

    foreach (RouteMatrix::routes() as $key => [$method, $uri]) {
        if ($method === 'GET') {
            continue;
        }
        $response = $this->actingAs($user)->json($method, RouteMatrix::url($uri), []);
        expect($response->getStatusCode())->toBe(419, $key)->and($response->json())->toBe(['message' => 'CSRF token mismatch.']);
        $checked++;
    }

    expect($checked)->toBeGreaterThan(40);
});

it('returns 419 for a wrong token and lets the right one through the CSRF layer', function () {
    CsrfEnforced::enable();
    $this->seed(DemoSeeder::class);
    $cookie = $this->get('/sanctum/csrf-cookie');
    $cookie->assertNoContent();
    $session = carrySession($cookie);

    // The browser sends the cookie value back as the header; it is the ENCRYPTED token, which the middleware decrypts.
    $token = collect($cookie->headers->getCookies())->first(fn ($c) => $c->getName() === 'XSRF-TOKEN')->getValue();

    $body = ['email' => 'alice@northwind.test', 'password' => 'wrong-password-1'];
    $this->postJson('/api/v1/auth/login', $body, ['X-XSRF-TOKEN' => 'not-the-token'])->assertStatus(419);
    $this->postJson('/api/v1/auth/login', $body, ['X-XSRF-TOKEN' => ''])->assertStatus(419);
    $this->postJson('/api/v1/auth/login', $body, ['X-XSRF-TOKEN' => $token])->assertStatus(422);

    expect($session)->not->toBeEmpty();
});

it('sets XSRF-TOKEN (readable by the SPA) and an HttpOnly SameSite=Lax session cookie on /sanctum/csrf-cookie', function () {
    $response = $this->get('/sanctum/csrf-cookie')->assertNoContent();
    $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());

    expect($cookies->has('XSRF-TOKEN'))->toBeTrue()
        ->and($cookies['XSRF-TOKEN']->isHttpOnly())->toBeFalse()
        ->and($cookies->has(config('session.cookie')))->toBeTrue()
        ->and($cookies[config('session.cookie')]->isHttpOnly())->toBeTrue()
        ->and(strtolower((string) $cookies[config('session.cookie')]->getSameSite()))->toBe('lax');
});
