<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

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
    });
});
