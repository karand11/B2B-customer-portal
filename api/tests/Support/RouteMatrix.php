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
    public const ROUTES = [
        'GET api/v1/health' => 'public',
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
}
