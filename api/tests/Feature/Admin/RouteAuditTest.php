<?php

use Illuminate\Support\Facades\Cache;
use Tests\Support\RouteMatrix;

/** @return array<string, array{string, string, list<string>}> "METHOD uri" => [method, uri, resolved middleware] for every api route (HEAD dropped). */
function routeAuditRoutes(): array
{
    $router = app('router');
    $routes = [];

    foreach ($router->getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes["{$method} {$route->uri()}"] = [$method, $route->uri(), $router->gatherRouteMiddleware($route)];
        }
    }

    return $routes;
}

/** The access a route REALLY has, derived from its middleware: public, session, auth, customer, staff or admin ('missing-active' when auth lacks `active`). */
function routeAuditActualAccess(array $middleware): string
{
    $has = fn (string $needle) => collect($middleware)->contains(fn ($m) => str_contains($m, $needle));
    $role = collect($middleware)->first(fn ($m) => str_contains($m, 'RoleMiddleware:'));

    if (! $has('Authenticate:sanctum')) {
        return 'public';
    }
    if (! $has('EnsureUserIsActive')) {
        return 'session';
    }

    return match (substr((string) $role, strpos((string) $role, ':') + 1)) {
        'admin' => 'admin',
        'staff|admin' => 'staff',
        'customer' => 'customer',
        default => 'auth', // none, or customer|staff|admin
    };
}

/** Replace every {param}; an UNKNOWN parameter name fails the test, so a new kind of id forces the author to extend this map. */
function routeAuditUrl(string $uri, ?string $malformed = null): string
{
    $map = [
        'number' => 'ORD-AAAAA-AAAAA', 'invoice' => '00000000-0000-4000-8000-000000000000', 'attachment' => '00000000-0000-4000-8000-000000000000',
        'import' => '00000000-0000-4000-8000-000000000000', 'product' => '999999999', 'company' => '999999999', 'user' => '999999999',
        'productId' => '999999999', 'id' => '999999999',
    ];

    return '/'.preg_replace_callback('/\{(\w+)\}/', function ($m) use ($map, $malformed) {
        array_key_exists($m[1], $map) || test()->fail("Route parameter [{$m[1]}] has no substitution in routeAuditUrl().");

        return $malformed ?? $map[$m[1]];
    }, $uri);
}

function routeAuditAllowed(string $role, string $access): bool
{
    return match ($access) {
        'public', 'session', 'auth' => true,
        'customer' => $role === 'customer',
        'staff' => in_array($role, ['staff', 'admin'], true),
        'admin' => $role === 'admin',
    };
}

it('has exactly the routes of the hand-written matrix', function () {
    $actual = array_keys(routeAuditRoutes());
    $expected = array_keys(RouteMatrix::ROUTES);

    expect(array_values(array_diff($actual, $expected)))->toBe([], 'Routes missing from tests/Support/RouteMatrix.php: decide who may call them.')
        ->and(array_values(array_diff($expected, $actual)))->toBe([], 'Matrix entries without a route.');
});

it('gives every route the access the matrix says (including `active` on every authenticated route)', function () {
    foreach (routeAuditRoutes() as $key => [, , $middleware]) {
        expect(routeAuditActualAccess($middleware))->toBe(RouteMatrix::ROUTES[$key], "Access of [{$key}]");
    }
});

it('protects every /admin route with a staff or admin role and no public or any-role access', function () {
    $admin = array_filter(routeAuditRoutes(), fn ($r) => str_starts_with($r[1], 'api/v1/admin'));

    expect($admin)->not->toBeEmpty();
    foreach ($admin as $key => [, , $middleware]) {
        expect(routeAuditActualAccess($middleware))->toBeIn(['staff', 'admin'], "Access of [{$key}]");
    }
});

it('answers every route correctly for each kind of caller', function (string $who) {
    $users = [
        'customer' => fn () => makeUser('customer'), 'staff' => fn () => makeUser('staff'), 'admin' => fn () => makeUser('admin'),
        'deactivated' => fn () => makeUser('admin', null, ['active' => false]),
        'stale' => fn () => makeUser('admin'),
    ];
    $user = $who === 'guest' ? null : $users[$who]();
    $role = in_array($who, ['deactivated', 'stale'], true) ? 'admin' : $who;
    $checked = 0;

    foreach (routeAuditRoutes() as $key => [$method, $uri]) {
        Cache::flush(); // the rate limiters are not under test here
        $access = RouteMatrix::ROUTES[$key];

        if ($user !== null) {
            $this->actingAs($user);
            $who === 'stale' && $this->withSession(['session_epoch' => 999]);
        }

        $status = $this->json($method, routeAuditUrl($uri), [])->getStatusCode();
        $checked++;

        expect($status)->toBeLessThan(500, "{$who}: {$key} -> {$status}");
        match (true) {
            $access === 'public' => expect($status)->not->toBeIn([401, 403], "{$who}: {$key} -> {$status}"),
            $who === 'guest' => expect($status)->toBe(401, "{$who}: {$key} -> {$status}"),
            in_array($who, ['deactivated', 'stale'], true) => expect($status)->toBe($access === 'session' ? 204 : 401, "{$who}: {$key} -> {$status}"),
            routeAuditAllowed($role, $access) => expect($status)->not->toBeIn([401, 403], "{$who}: {$key} -> {$status}"),
            default => expect($status)->toBe(403, "{$who}: {$key} -> {$status}"),
        };
    }

    expect($checked)->toBe(count(RouteMatrix::ROUTES));
})->with(['guest', 'customer', 'staff', 'admin', 'deactivated', 'stale']);

it('answers a malformed id with the plain 404 for every role before any middleware', function (string $who) {
    $user = $who === 'guest' ? null : makeUser($who);

    foreach (routeAuditRoutes() as $key => [$method, $uri]) {
        if (! str_contains($uri, '{')) {
            continue;
        }
        Cache::flush();
        $user === null || $this->actingAs($user);

        $this->json($method, routeAuditUrl($uri, 'bad!id'), [])->assertNotFound()->assertExactJson(NOT_FOUND);
    }
})->with(['guest', 'customer', 'staff', 'admin']);
