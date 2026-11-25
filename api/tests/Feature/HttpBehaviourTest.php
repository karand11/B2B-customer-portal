<?php

use Database\Seeders\DemoSeeder;

it('still serves the public health endpoint', function () {
    $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('answers the CORS preflight for the SPA origin with credentials', function () {
    $response = $this->withHeaders([
        'Origin' => 'http://localhost:5173',
        'Access-Control-Request-Method' => 'GET',
        'Access-Control-Request-Headers' => 'x-xsrf-token',
    ])->options('/api/v1/me');

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:5173')
        ->and($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
});

it('does not grant CORS to other origins', function () {
    $response = $this->withHeaders([
        'Origin' => 'http://evil.example',
        'Access-Control-Request-Method' => 'GET',
    ])->options('/api/v1/me');

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
    expect(config('cors.allowed_origins'))->not->toContain('*');
});

it('returns JSON errors without stack traces', function () {
    config(['app.debug' => false]);

    $notFound = $this->getJson('/api/v1/does-not-exist')->assertNotFound();
    $notAllowed = $this->postJson('/api/v1/health')->assertStatus(405);

    foreach ([$notFound, $notAllowed] as $response) {
        expect($response->headers->get('Content-Type'))->toContain('application/json')
            ->and($response->json())->not->toHaveKeys(['trace', 'exception', 'file', 'line']);
    }
});

it('returns 401 JSON to guests on protected routes even without an Accept header', function (string $uri) {
    $this->withoutHeader('Accept');

    $response = $this->get($uri)->assertUnauthorized();

    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->headers->has('Location'))->toBeFalse()
        ->and($response->json('message'))->toBe('Unauthenticated.');
})->with(['/api/v1/me', '/api/v1/staff/ping', '/api/v1/admin/ping']);

it('rejects login without a stateful origin with a clean 419 instead of a 500', function (?string $origin) {
    config(['app.debug' => false]);
    $this->seed(DemoSeeder::class);
    $this->withoutHeader('Origin');
    $headers = $origin ? ['Origin' => $origin] : [];

    $this->postJson('/api/v1/auth/login', ['email' => 'alice@northwind.test', 'password' => TEST_PASSWORD], $headers)
        ->assertStatus(419)
        ->assertExactJson(['message' => 'CSRF token mismatch.']);

    $this->assertGuest('web');
})->with([
    'no origin' => [null],
    'foreign origin' => ['http://evil.example'],
]);
