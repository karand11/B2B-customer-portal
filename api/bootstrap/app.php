<?php

use App\Http\Middleware\EnforceProductionConfig;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequestContext;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustConfiguredHosts;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Support\InvalidConfiguration;
use App\Support\ReportedException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Outermost first: security headers wrap everything (errors and CORS preflights included), then the request id, then the
        // production configuration guard. Trusted proxies and hosts come from config at request time (config/portal.php), never from `*`.
        $middleware->prepend([SecurityHeaders::class, RequestContext::class, EnforceProductionConfig::class]);
        $middleware->trustHosts(subdomains: false);
        $middleware->replace(TrustHosts::class, TrustConfiguredHosts::class);
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);
        // API guests always get 401 JSON; there is no login route to redirect to.
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->statefulApi();
        // PlaceOrderRequest trims po_reference itself, after checking the raw value for NUL bytes (trim would hide a trailing one).
        $middleware->trimStrings(except: ['po_reference']);
        $middleware->throttleApi();
        // Also covers GET /sanctum/csrf-cookie (web group) with the same 60/min limiter.
        $middleware->web(append: ['throttle:api']);
        $middleware->alias(['role' => RoleMiddleware::class, 'active' => EnsureUserIsActive::class]);
        // Order: authenticate, active (deactivated or stale session = 401), role (wrong role = 403), then the framework default
        // (rate limiters, route model binding), so a wrong role never counts against a limiter and no lookup runs before the role check.
        $middleware->appendToPriorityList(after: AuthenticatesRequests::class, append: EnsureUserIsActive::class);
        $middleware->appendToPriorityList(after: EnsureUserIsActive::class, append: RoleMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A bad TRUSTED_PROXIES or TRUSTED_HOSTS stops the boot while the facades do not exist yet, so the reporters below would die with
        // "A facade root has not been set" and hide the cause. The message names the variable, never its value.
        $exceptions->report(function (InvalidConfiguration $e) {
            error_log($e->getMessage());
        })->stop();

        // Guests get 401 JSON, never a redirect to a non-existent login route.
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*', 'sanctum/*') || $request->expectsJson()
        );

        // QueryException::getMessage() ends with the SQL with every bound value filled in (IBANs, addresses, tax ids, PO numbers...).
        // Log only the placeholders SQL and the SQLSTATE, then stop so the default reporter does not log the message as well.
        $exceptions->report(function (QueryException $e) {
            Log::error('Database query failed.', [
                'sqlstate' => $e->errorInfo[0] ?? null, 'sql' => $e->getSql(), 'connection' => $e->getConnectionName(), 'code' => $e->getCode(),
            ]);
        })->stop();

        // Any other exception: class, message with email addresses masked, location and the trace as frames only (no arguments), the previous
        // exceptions the same way (ReportedException). The default reporter would log the exception object, and the messages of mail and queue
        // failures contain addresses. Runs after the QueryException reporter above.
        $exceptions->report(function (Throwable $e) {
            Log::error($e::class, ReportedException::context($e));
        })->stop();

        // With APP_DEBUG=true the default renderer echoes the message (SQL plus values) to the client: always a generic 500 on the API.
        $exceptions->render(fn (QueryException $e, $request) => $request->is('api/*')
            ? response()->json(['message' => 'Server Error'], 500)
            : null);

        // A Host header outside TRUSTED_HOSTS: a generic 400 that never echoes the host (the framework turns the exception into a BadRequestHttpException).
        $exceptions->render(fn (BadRequestHttpException $e, $request) => $e->getPrevious() instanceof RequestExceptionInterface
            ? response()->json(['message' => 'Bad Request.'], 400)
            : null);

        // A body above PHP post_max_size is rejected by the framework before the app sees the file.
        $exceptions->render(fn (PostTooLargeException $e, $request) => $request->is('api/*')
            ? response()->json(['message' => 'The upload is too large.'], 413)
            : null);

        // One generic 404 body for api/*: ModelNotFoundException (wrapped by Laravel) must not leak the model class name,
        // and a policy denyAsNotFound() (a plain HttpException 404, not a NotFoundHttpException) must look identical.
        $exceptions->render(fn (HttpExceptionInterface $e, $request) => $request->is('api/*') && $e->getStatusCode() === 404
            ? response()->json(['message' => 'Not found.'], 404)
            : null);
    })->create();
