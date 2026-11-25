<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        $middleware->throttleApi();
        // Also covers GET /sanctum/csrf-cookie (web group) with the same 60/min limiter.
        $middleware->web(append: ['throttle:api']);
        $middleware->alias(['role' => RoleMiddleware::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Guests get 401 JSON, never a redirect to a non-existent login route.
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*', 'sanctum/*') || $request->expectsJson()
        );

        // One generic 404 body for api/*: ModelNotFoundException (wrapped by Laravel) must not leak the model class name.
        $exceptions->render(fn (NotFoundHttpException $e, $request) => $request->is('api/*')
            ? response()->json(['message' => 'Not found.'], 404)
            : null);
    })->create();
