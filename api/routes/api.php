<?php

use App\Http\Controllers\Api\V1\AcceptInvitationController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\CompanyController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\V1\Admin\ProductImportController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\UserInvitationController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\HealthReadyController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\QuoteAttachmentController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Models\ProductImport;
use Illuminate\Support\Facades\Route;

// Order numbers (ORD-XXXXX-XXXXX) are the only public order id; anything else is a 404.
Route::pattern('number', '[A-Za-z0-9-]{1,32}');
// Attachments are addressed by random lowercase UUID; anything else never reaches a controller or middleware (404).
Route::pattern('attachment', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

// Invoices are addressed by random lowercase UUID (never the sequential number); anything else is a plain 404 before any middleware.
Route::pattern('invoice', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

// Back-office ids: numeric for products, companies and users (reachable by staff and admin only, so enumeration is not a customer risk),
// a random UUID for CSV imports. Anything else is a plain 404 before any middleware.
Route::pattern('product', '[0-9]{1,18}');
Route::pattern('company', '[0-9]{1,18}');
Route::pattern('user', '[0-9]{1,18}');
Route::pattern('import', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
// An import token only resolves for the user who uploaded it: another user's token is indistinguishable from an unknown one (404).
Route::bind('import', fn (string $id) => ProductImport::where('user_id', request()->user()?->id)->whereKey($id)->firstOrFail());

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));
    // Its own bucket (a load balancer probes it every few seconds, from one address): never the guest `api` bucket of the visitors.
    Route::get('/health/ready', HealthReadyController::class)->withoutMiddleware('throttle:api')->middleware('throttle:health-ready');

    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login-ip');
        Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-reset');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');
        Route::post('/accept-invitation', AcceptInvitationController::class)->middleware('throttle:password-reset');
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    });

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', MeController::class);
        Route::get('/staff/ping', fn () => response()->json(['status' => 'ok']))->middleware('role:staff|admin');
        Route::get('/admin/ping', fn () => response()->json(['status' => 'ok']))->middleware('role:admin');

        // Back office. Tier S (staff or admin) and tier A (admin only); the role check runs before every limiter and lookup.
        Route::prefix('admin')->group(function () {
            Route::middleware('role:staff|admin')->group(function () {
                Route::get('/dashboard', DashboardController::class);

                Route::get('/products', [AdminProductController::class, 'index']);
                Route::get('/products/{product}', [AdminProductController::class, 'show']);

                Route::get('/companies', [CompanyController::class, 'index']);
                Route::get('/companies/{company}', [CompanyController::class, 'show']);

                Route::middleware('throttle:admin-writes')->group(function () {
                    Route::post('/products', [AdminProductController::class, 'store']);
                    Route::put('/products/{product}', [AdminProductController::class, 'update']);
                    Route::post('/products/{product}/activate', [AdminProductController::class, 'activate']);
                    Route::post('/products/{product}/deactivate', [AdminProductController::class, 'deactivate']);
                    Route::put('/companies/{company}/billing', [CompanyController::class, 'updateBilling']);

                    // Every attempt counts, including 422s.
                    Route::middleware(['throttle:admin-import', 'throttle:admin-import-hourly'])->group(function () {
                        Route::post('/products/imports', [ProductImportController::class, 'preview']);
                        Route::post('/products/imports/{import}/apply', [ProductImportController::class, 'apply']);
                    });
                });
            });

            Route::middleware('role:admin')->group(function () {
                Route::get('/audit-log', [AuditLogController::class, 'index']);
                Route::get('/users', [UserController::class, 'index']);
                Route::get('/users/{user}', [UserController::class, 'show']);

                Route::middleware('throttle:admin-writes')->group(function () {
                    Route::post('/companies', [CompanyController::class, 'store']);
                    Route::put('/companies/{company}', [CompanyController::class, 'update']);

                    Route::patch('/users/{user}', [UserController::class, 'update']);
                    Route::put('/users/{user}/assignment', [UserController::class, 'assign']);
                    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
                    Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate']);
                    Route::post('/users/{user}/password-reset', [UserController::class, 'passwordReset']);
                    Route::delete('/users/{user}/invitation', [UserInvitationController::class, 'revoke']);

                    Route::middleware('throttle:admin-invites')->group(function () {
                        Route::post('/users', [UserController::class, 'store']);
                        Route::post('/users/{user}/invitation', [UserInvitationController::class, 'resend']);
                    });
                });
            });
        });

        // /products/filters must be declared before /products/{id}.
        Route::get('/products', [ProductController::class, 'index'])->middleware('throttle:catalog-search');
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
            Route::post('/orders/{number}/reorder', [OrderController::class, 'reorder'])->middleware('throttle:orders-reorder');

            Route::post('/quotes', [QuoteController::class, 'store'])->middleware('throttle:quotes-create');
            Route::post('/quotes/{number}/accept', [QuoteController::class, 'accept'])->middleware('throttle:orders-create');
            Route::post('/quotes/{number}/decline', [QuoteController::class, 'decline']);
        });

        Route::middleware('role:staff|admin')->group(function () {
            Route::post('/quotes/{number}/review', [QuoteController::class, 'review']);
            Route::put('/quotes/{number}/pricing', [QuoteController::class, 'pricing']);
            Route::post('/quotes/{number}/send', [QuoteController::class, 'send']);
            Route::post('/quotes/{number}/reject', [QuoteController::class, 'reject']);
            Route::post('/quotes/{number}/reopen', [QuoteController::class, 'reopen']);
            Route::put('/quotes/{number}/internal-note', [QuoteController::class, 'internalNote']);

            // Customers have no write endpoint for invoices. Every attempt counts against invoices-write, including 422s.
            Route::middleware('throttle:invoices-write')->group(function () {
                Route::post('/orders/{number}/invoice', [InvoiceController::class, 'storeForOrder']);
                Route::post('/invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid']);
                Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void']);
            });
        });

        Route::patch('/orders/{number}/status', [OrderController::class, 'status'])
            ->middleware('role:staff|admin');

        Route::middleware('role:customer|staff|admin')->group(function () {
            Route::get('/orders', [OrderController::class, 'index'])->middleware('throttle:order-search');
            Route::get('/orders/{number}', [OrderController::class, 'show']);
            Route::post('/orders/{number}/cancel', [OrderController::class, 'cancel']);

            Route::get('/quotes', [QuoteController::class, 'index'])->middleware('throttle:order-search');
            Route::get('/quotes/{number}', [QuoteController::class, 'show']);
            Route::post('/quotes/{number}/attachments', [QuoteAttachmentController::class, 'store'])->middleware(['throttle:quote-uploads', 'throttle:quote-uploads-hourly']);
            Route::get('/quotes/{number}/attachments/{attachment}', [QuoteAttachmentController::class, 'show']);
            Route::delete('/quotes/{number}/attachments/{attachment}', [QuoteAttachmentController::class, 'destroy'])->middleware('throttle:quote-attachment-delete');

            Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('throttle:order-search');
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
            // Two buckets: 20/min per user and 60/min per IP address. HEAD (answered by every GET route) is refused with 405 in the controller.
            Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->middleware(['throttle:invoice-pdf', 'throttle:invoice-pdf-ip']);
        });
    });
});
