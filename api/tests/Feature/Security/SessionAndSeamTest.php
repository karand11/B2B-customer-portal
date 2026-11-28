<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Services\AuditLog;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

it('refuses an authenticated user on a request without a session (the epoch check fails closed)', function () {
    $user = makeUser('customer');

    $request = Request::create('/api/v1/me');
    $request->setUserResolver(fn () => $user);

    expect(fn () => (new EnsureUserIsActive)->handle($request, fn () => response('ok')))->toThrow(AuthenticationException::class);
});

it('still passes a user whose session carries the current epoch, and refuses a stale or missing epoch', function (?int $stored, bool $passes) {
    $user = makeUser('customer');
    $request = Request::create('/api/v1/me');
    $request->setUserResolver(fn () => $user);
    $session = new Store('test', new ArraySessionHandler(10));
    $stored === null || $session->put('session_epoch', $stored);
    $request->setLaravelSession($session);

    $call = fn () => (new EnsureUserIsActive)->handle($request, fn () => response('ok'));

    $passes ? expect($call()->getContent())->toBe('ok') : expect($call)->toThrow(AuthenticationException::class);
})->with([[1, true], [2, false], [null, false]]);

it('lets a guest through the middleware (authentication is another layer)', function () {
    $request = Request::create('/api/v1/me');
    $request->setUserResolver(fn () => null);

    expect((new EnsureUserIsActive)->handle($request, fn () => response('ok'))->getContent())->toBe('ok');
});

it('answers 401 to a user-bearing request from a non-stateful origin, on every kind of route', function () {
    $user = makeUser('admin');
    $this->actingAs($user);
    $this->withHeaders(['Origin' => 'http://evil.test']);

    foreach (['/api/v1/me', '/api/v1/orders', '/api/v1/admin/users', '/api/v1/admin/audit-log'] as $uri) {
        $this->getJson($uri)->assertUnauthorized();
    }
    $this->postJson('/api/v1/admin/companies', ['name' => 'X'])->assertUnauthorized();

    $this->withHeaders(['Origin' => 'http://localhost:5173']);
    $this->getJson('/api/v1/me')->assertOk();
});

it('adds the user id (an integer, nothing else) to the log context after authentication', function () {
    $user = makeUser('customer');
    $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

    expect(Context::get('user_id'))->toBe($user->id)->and(Context::all())->not->toHaveKey('email');
});

it('honours the AuditLog transaction-depth seam only while unit tests run', function () {
    // In the test environment the seam is on: a call at the harness transaction level is refused...
    expect(fn () => AuditLog::record('order.status_changed', 'order', 'ORD-1', null, null, [], ['status' => 'confirmed']))->toThrow(LogicException::class);

    // ...but outside tests a non-zero value is ignored: only the real transaction depth counts.
    app()->detectEnvironment(fn () => 'production');
    AuditLog::$baseTransactionLevel = 50;

    DB::transaction(function () {
        AuditLog::record('order.status_changed', 'order', 'ORD-1', null, null, ['status' => 'pending'], ['status' => 'confirmed']);
    });

    expect(auditLogRows('order.status_changed'))->toHaveCount(1);
});
