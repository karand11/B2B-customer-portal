<?php

use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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

        // One generic 404 body for api/*: ModelNotFoundException (wrapped by Laravel) must not leak the model class name,
        // and a policy denyAsNotFound() (a plain HttpException 404, not a NotFoundHttpException) must look identical.
        $exceptions->render(fn (HttpExceptionInterface $e, $request) => $request->is('api/*') && $e->getStatusCode() === 404
            ? response()->json(['message' => 'Not found.'], 404)
            : null);
    })->create();
