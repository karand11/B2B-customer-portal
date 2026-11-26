<?php

use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // API guests always get 401 JSON; there is no login route to redirect to.
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->statefulApi();
        // PlaceOrderRequest trims po_reference itself, after checking the raw value for NUL bytes (trim would hide a trailing one).
        $middleware->trimStrings(except: ['po_reference']);
        $middleware->throttleApi();
        // Also covers GET /sanctum/csrf-cookie (web group) with the same 60/min limiter.
        $middleware->web(append: ['throttle:api']);
        $middleware->alias(['role' => RoleMiddleware::class]);
        // A wrong role is a 403 before any rate limiter (api, orders-create) counts the request or any lookup runs.
        $middleware->appendToPriorityList(after: AuthenticatesRequests::class, append: RoleMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
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

        // With APP_DEBUG=true the default renderer echoes the message (SQL plus values) to the client: always a generic 500 on the API.
        $exceptions->render(fn (QueryException $e, $request) => $request->is('api/*')
            ? response()->json(['message' => 'Server Error'], 500)
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
