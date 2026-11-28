<?php

use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\Support\RateLimits;
use Tests\Support\RouteMatrix;

/** @return list<string> limiter names of the `throttle:` middleware of a resolved middleware list */
function secThrottleNames(array $middleware): array
{
    $names = [];
    foreach ($middleware as $m) {
        if (is_string($m) && preg_match('/(?:ThrottleRequests|^throttle):([\w.-]+)$/D', $m, $match) === 1) {
            $names[] = $match[1];
        }
    }

    return $names;
}

it('audits ALL routes: outside /api only the framework health route and the CSRF cookie exist (no welcome page, no storage route)', function () {
    $outside = array_filter(RouteMatrix::routes(false), fn ($r, $key) => ! str_starts_with($r[1], 'api/'), ARRAY_FILTER_USE_BOTH);

    expect(array_keys($outside))->toEqualCanonicalizing(['GET up', 'GET sanctum/csrf-cookie']);
    foreach (RouteMatrix::routes(false) as $key => [, $uri]) {
        expect($uri)->not->toStartWith('storage')->not->toBe('/')->not->toBeIn(['', '/']);
    }
});

it('serves no page on the API host root', function () {
    $this->get('/')->assertNotFound();
    $this->getJson('/')->assertNotFound();
});

it('has exactly these public routes (a literal list: adding one is a visible diff)', function () {
    $public = array_keys(array_filter(RouteMatrix::ROUTES, fn ($access) => $access === 'public'));

    expect($public)->toEqualCanonicalizing([
        'GET api/v1/health',
        'GET api/v1/health/ready',
        'POST api/v1/auth/login',
        'POST api/v1/auth/forgot-password',
        'POST api/v1/auth/reset-password',
        'POST api/v1/auth/accept-invitation',
    ]);
});

it('puts at least one rate limiter on every api route and declares every limiter name in the rate-limit table', function () {
    $used = [];

    foreach (RouteMatrix::routes() as $key => [, , $middleware]) {
        $names = secThrottleNames($middleware);
        expect($names)->not->toBe([], "{$key} has no throttle middleware");
        $used = [...$used, ...$names];
    }

    expect(array_values(array_diff(array_unique($used), RateLimits::names())))->toBe([], 'A limiter on a route is missing from tests/Support/RateLimits.php.');
    foreach (RateLimits::names() as $name) {
        expect(RateLimiter::limiter($name))->not->toBeNull("limiter [{$name}] is not registered");
    }
});

it('covers every non-GET public route with a limiter of its own (not only the shared api bucket)', function () {
    foreach (RouteMatrix::routes() as $key => [$method, , $middleware]) {
        if ($method !== 'GET' && RouteMatrix::ROUTES[$key] === 'public') {
            expect(array_values(array_diff(secThrottleNames($middleware), ['api'])))->not->toBe([], "{$key} only has the shared api limiter");
        }
    }
});

it('puts a dedicated limiter on every expensive or abusable route of the Phase 7 list', function (string $key, string $limiter) {
    expect(secThrottleNames(RouteMatrix::routes()[$key][2]))->toContain($limiter);
})->with([
    ['GET api/v1/products', 'catalog-search'],
    ['GET api/v1/orders', 'order-search'],
    ['GET api/v1/quotes', 'order-search'],
    ['GET api/v1/invoices', 'order-search'],
    ['DELETE api/v1/quotes/{number}/attachments/{attachment}', 'quote-attachment-delete'],
    ['GET api/v1/health/ready', 'health-ready'],
    ['POST api/v1/orders/{number}/reorder', 'orders-reorder'],
    ['POST api/v1/orders', 'orders-create'],
    ['POST api/v1/quotes/{number}/accept', 'orders-create'],
    ['POST api/v1/quotes', 'quotes-create'],
    ['POST api/v1/quotes/{number}/attachments', 'quote-uploads'],
    ['GET api/v1/invoices/{invoice}/pdf', 'invoice-pdf'],
    ['GET api/v1/invoices/{invoice}/pdf', 'invoice-pdf-ip'],
    ['POST api/v1/invoices/{invoice}/void', 'invoices-write'],
    ['POST api/v1/admin/products', 'admin-writes'],
    ['POST api/v1/admin/products/imports', 'admin-import'],
    ['POST api/v1/admin/users', 'admin-invites'],
    ['POST api/v1/auth/login', 'login-ip'],
]);

it('runs the Sanctum stateful middleware on every api route, so CSRF applies to browsers', function () {
    foreach (RouteMatrix::routes() as $key => [, , $middleware]) {
        expect(in_array(EnsureFrontendRequestsAreStateful::class, $middleware, true))->toBeTrue("{$key} is not stateful");
    }
});

it('lets the order-search limiter ignore requests without q', function () {
    $limiter = RateLimiter::limiter('order-search');

    expect($limiter(Request::create('/api/v1/orders', 'GET')))->toBeInstanceOf(Unlimited::class);
});

it('keeps the readiness probe out of the guest api bucket and puts nothing else on its own limiter', function () {
    expect(secThrottleNames(RouteMatrix::routes()['GET api/v1/health/ready'][2]))->toBe(['health-ready']);

    foreach (RouteMatrix::routes() as $key => [, , $middleware]) {
        $key === 'GET api/v1/health/ready' || expect(in_array('health-ready', secThrottleNames($middleware), true))->toBe(false, $key);
    }
});
