<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

// Order numbers (ORD-XXXXX-XXXXX) are the only public order id; anything else is a 404.
Route::pattern('number', '[A-Za-z0-9-]{1,32}');

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login-ip');
        Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-reset');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', MeController::class);
        Route::get('/staff/ping', fn () => response()->json(['status' => 'ok']))->middleware('role:staff|admin');
        Route::get('/admin/ping', fn () => response()->json(['status' => 'ok']))->middleware('role:admin');

        // /products/filters must be declared before /products/{id}.
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/filters', [ProductController::class, 'filters']);
        Route::get('/products/{id}', [ProductController::class, 'show'])->where('id', '[0-9]{1,18}');
        Route::get('/categories', [CategoryController::class, 'index']);

        // Role middleware runs before any controller lookup, so a wrong role is 403 for every id, existing or not.
        Route::middleware('role:customer')->group(function () {
            Route::get('/cart', [CartController::class, 'show']);
            Route::delete('/cart', [CartController::class, 'clear']);
            Route::post('/cart/items', [CartController::class, 'add']);
            Route::patch('/cart/items/{productId}', [CartController::class, 'update'])->where('productId', '[0-9]{1,18}');
            Route::delete('/cart/items/{productId}', [CartController::class, 'remove'])->where('productId', '[0-9]{1,18}');
            Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:orders-create');
            Route::post('/orders/{number}/reorder', [OrderController::class, 'reorder']);
        });

        Route::patch('/orders/{number}/status', [OrderController::class, 'status'])
            ->middleware('role:staff|admin');

        Route::middleware('role:customer|staff|admin')->group(function () {
            Route::get('/orders', [OrderController::class, 'index']);
            Route::get('/orders/{number}', [OrderController::class, 'show']);
            Route::post('/orders/{number}/cancel', [OrderController::class, 'cancel']);
        });
    });
});
