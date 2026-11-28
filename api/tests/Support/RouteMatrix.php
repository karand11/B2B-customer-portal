<?php

namespace Tests\Support;

/**
 * The hand-written access decision for EVERY api route. RouteAuditTest compares it with the real router in both directions, so a new
 * route fails the suite until someone decides who may call it, and a removed route fails too.
 *
 * public   no authentication (own limiters apply)
 * session  auth:sanctum only: logout must keep working for a session the `active` middleware would reject
 * auth     any signed-in role (no role middleware, or role:customer|staff|admin)
 * customer role:customer
 * staff    role:staff|admin
 * admin    role:admin
 */
class RouteMatrix
{
    /**
     * Every route of the application (HEAD dropped), keyed "METHOD uri".
     *
     * @return array<string, array{string, string, list<string>}> [method, uri, resolved middleware]
     */
    public static function routes(bool $apiOnly = true): array
    {
        $router = app('router');
        $routes = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($apiOnly && ! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes["{$method} {$route->uri()}"] = [$method, $route->uri(), $router->gatherRouteMiddleware($route)];
            }
        }

        return $routes;
    }

    /** Replace every {param} of a route URI with a well-formed value (or $malformed); an unknown parameter name fails loudly. */
    public static function url(string $uri, ?string $malformed = null): string
    {
        $map = [
            'number' => 'ORD-AAAAA-AAAAA', 'invoice' => '00000000-0000-4000-8000-000000000000', 'attachment' => '00000000-0000-4000-8000-000000000000',
            'import' => '00000000-0000-4000-8000-000000000000', 'product' => '999999999', 'company' => '999999999', 'user' => '999999999',
            'productId' => '999999999', 'id' => '999999999',
        ];

        return '/'.preg_replace_callback('/\{(\w+)\}/', function ($m) use ($map, $malformed) {
            array_key_exists($m[1], $map) || throw new \LogicException("Route parameter [{$m[1]}] has no substitution in RouteMatrix::url().");

            return $malformed ?? $map[$m[1]];
        }, $uri);
    }

    public const ROUTES = [
        'GET api/v1/health' => 'public',
        'GET api/v1/health/ready' => 'public',
        'POST api/v1/auth/login' => 'public',
        'POST api/v1/auth/forgot-password' => 'public',
        'POST api/v1/auth/reset-password' => 'public',
        'POST api/v1/auth/accept-invitation' => 'public',
        'POST api/v1/auth/logout' => 'session',

        'GET api/v1/me' => 'auth',
        'GET api/v1/products' => 'auth',
        'GET api/v1/products/filters' => 'auth',
        'GET api/v1/products/{id}' => 'auth',
        'GET api/v1/categories' => 'auth',
        'GET api/v1/orders' => 'auth',
        'GET api/v1/orders/{number}' => 'auth',
        'POST api/v1/orders/{number}/cancel' => 'auth',
        'GET api/v1/quotes' => 'auth',
        'GET api/v1/quotes/{number}' => 'auth',
        'POST api/v1/quotes/{number}/attachments' => 'auth',
        'GET api/v1/quotes/{number}/attachments/{attachment}' => 'auth',
        'DELETE api/v1/quotes/{number}/attachments/{attachment}' => 'auth',
        'GET api/v1/invoices' => 'auth',
        'GET api/v1/invoices/{invoice}' => 'auth',
        'GET api/v1/invoices/{invoice}/pdf' => 'auth',

        'GET api/v1/cart' => 'customer',
        'DELETE api/v1/cart' => 'customer',
        'POST api/v1/cart/items' => 'customer',
        'PATCH api/v1/cart/items/{productId}' => 'customer',
        'DELETE api/v1/cart/items/{productId}' => 'customer',
        'POST api/v1/orders' => 'customer',
        'POST api/v1/orders/{number}/reorder' => 'customer',
        'POST api/v1/quotes' => 'customer',
        'POST api/v1/quotes/{number}/accept' => 'customer',
        'POST api/v1/quotes/{number}/decline' => 'customer',

        'GET api/v1/staff/ping' => 'staff',
        'PATCH api/v1/orders/{number}/status' => 'staff',
        'POST api/v1/orders/{number}/invoice' => 'staff',
        'POST api/v1/invoices/{invoice}/mark-paid' => 'staff',
        'POST api/v1/invoices/{invoice}/void' => 'staff',
        'POST api/v1/quotes/{number}/review' => 'staff',
        'PUT api/v1/quotes/{number}/pricing' => 'staff',
        'POST api/v1/quotes/{number}/send' => 'staff',
        'POST api/v1/quotes/{number}/reject' => 'staff',
        'POST api/v1/quotes/{number}/reopen' => 'staff',
        'PUT api/v1/quotes/{number}/internal-note' => 'staff',

        'GET api/v1/admin/dashboard' => 'staff',
        'GET api/v1/admin/products' => 'staff',
        'POST api/v1/admin/products' => 'staff',
        'GET api/v1/admin/products/{product}' => 'staff',
        'PUT api/v1/admin/products/{product}' => 'staff',
        'POST api/v1/admin/products/{product}/activate' => 'staff',
        'POST api/v1/admin/products/{product}/deactivate' => 'staff',
        'POST api/v1/admin/products/imports' => 'staff',
        'POST api/v1/admin/products/imports/{import}/apply' => 'staff',
        'GET api/v1/admin/companies' => 'staff',
        'GET api/v1/admin/companies/{company}' => 'staff',
        'PUT api/v1/admin/companies/{company}/billing' => 'staff',

        'GET api/v1/admin/ping' => 'admin',
        'POST api/v1/admin/companies' => 'admin',
        'PUT api/v1/admin/companies/{company}' => 'admin',
        'GET api/v1/admin/users' => 'admin',
        'POST api/v1/admin/users' => 'admin',
        'GET api/v1/admin/users/{user}' => 'admin',
        'PATCH api/v1/admin/users/{user}' => 'admin',
        'PUT api/v1/admin/users/{user}/assignment' => 'admin',
        'POST api/v1/admin/users/{user}/deactivate' => 'admin',
        'POST api/v1/admin/users/{user}/reactivate' => 'admin',
        'POST api/v1/admin/users/{user}/password-reset' => 'admin',
        'POST api/v1/admin/users/{user}/invitation' => 'admin',
        'DELETE api/v1/admin/users/{user}/invitation' => 'admin',
        'GET api/v1/admin/audit-log' => 'admin',
    ];

    /**
     * Whose data a route touches (Phase 7 gate: CrossCompanyMatrixTest, MassAssignmentSweepTest). The test compares the keys with the router, so a new route fails
     * the build until it is classified here; a route with a {number}, {invoice} or {attachment} parameter MUST be a company:* scope.
     *
     * public              no data of any user
     * shared              catalog and other data every signed-in user sees (no company column)
     * user                the signed-in user's own rows (cart, profile, session) or a new row of their own company
     * company:order|quote|invoice|attachment   rows of ONE company: a customer of another company gets an exact 404
     * admin               the back office: cross-company by design, staff or admin only
     */
    public const SCOPE = [
        'GET api/v1/health' => 'public',
        'GET api/v1/health/ready' => 'public',
        'POST api/v1/auth/login' => 'public',
        'POST api/v1/auth/forgot-password' => 'public',
        'POST api/v1/auth/reset-password' => 'public',
        'POST api/v1/auth/accept-invitation' => 'public',
        'POST api/v1/auth/logout' => 'user',
        'GET api/v1/me' => 'user',
        'GET api/v1/cart' => 'user',
        'DELETE api/v1/cart' => 'user',
        'POST api/v1/cart/items' => 'user',
        'PATCH api/v1/cart/items/{productId}' => 'user',
        'DELETE api/v1/cart/items/{productId}' => 'user',
        'POST api/v1/orders' => 'user',
        'POST api/v1/quotes' => 'user',
        'GET api/v1/products' => 'shared',
        'GET api/v1/products/filters' => 'shared',
        'GET api/v1/products/{id}' => 'shared',
        'GET api/v1/categories' => 'shared',
        'GET api/v1/staff/ping' => 'shared',
        'GET api/v1/admin/ping' => 'shared',
        'GET api/v1/orders' => 'company:order',
        'GET api/v1/orders/{number}' => 'company:order',
        'POST api/v1/orders/{number}/cancel' => 'company:order',
        'POST api/v1/orders/{number}/reorder' => 'company:order',
        'PATCH api/v1/orders/{number}/status' => 'company:order',
        'POST api/v1/orders/{number}/invoice' => 'company:order',
        'GET api/v1/quotes' => 'company:quote',
        'GET api/v1/quotes/{number}' => 'company:quote',
        'POST api/v1/quotes/{number}/accept' => 'company:quote',
        'POST api/v1/quotes/{number}/decline' => 'company:quote',
        'POST api/v1/quotes/{number}/review' => 'company:quote',
        'PUT api/v1/quotes/{number}/pricing' => 'company:quote',
        'POST api/v1/quotes/{number}/send' => 'company:quote',
        'POST api/v1/quotes/{number}/reject' => 'company:quote',
        'POST api/v1/quotes/{number}/reopen' => 'company:quote',
        'PUT api/v1/quotes/{number}/internal-note' => 'company:quote',
        'POST api/v1/quotes/{number}/attachments' => 'company:quote',
        'GET api/v1/quotes/{number}/attachments/{attachment}' => 'company:attachment',
        'DELETE api/v1/quotes/{number}/attachments/{attachment}' => 'company:attachment',
        'GET api/v1/invoices' => 'company:invoice',
        'GET api/v1/invoices/{invoice}' => 'company:invoice',
        'GET api/v1/invoices/{invoice}/pdf' => 'company:invoice',
        'POST api/v1/invoices/{invoice}/mark-paid' => 'company:invoice',
        'POST api/v1/invoices/{invoice}/void' => 'company:invoice',
        'GET api/v1/admin/dashboard' => 'admin',
        'GET api/v1/admin/products' => 'admin',
        'POST api/v1/admin/products' => 'admin',
        'GET api/v1/admin/products/{product}' => 'admin',
        'PUT api/v1/admin/products/{product}' => 'admin',
        'POST api/v1/admin/products/{product}/activate' => 'admin',
        'POST api/v1/admin/products/{product}/deactivate' => 'admin',
        'POST api/v1/admin/products/imports' => 'admin',
        'POST api/v1/admin/products/imports/{import}/apply' => 'admin',
        'GET api/v1/admin/companies' => 'admin',
        'GET api/v1/admin/companies/{company}' => 'admin',
        'PUT api/v1/admin/companies/{company}/billing' => 'admin',
        'POST api/v1/admin/companies' => 'admin',
        'PUT api/v1/admin/companies/{company}' => 'admin',
        'GET api/v1/admin/users' => 'admin',
        'POST api/v1/admin/users' => 'admin',
        'GET api/v1/admin/users/{user}' => 'admin',
        'PATCH api/v1/admin/users/{user}' => 'admin',
        'PUT api/v1/admin/users/{user}/assignment' => 'admin',
        'POST api/v1/admin/users/{user}/deactivate' => 'admin',
        'POST api/v1/admin/users/{user}/reactivate' => 'admin',
        'POST api/v1/admin/users/{user}/password-reset' => 'admin',
        'POST api/v1/admin/users/{user}/invitation' => 'admin',
        'DELETE api/v1/admin/users/{user}/invitation' => 'admin',
        'GET api/v1/admin/audit-log' => 'admin',
    ];
}
