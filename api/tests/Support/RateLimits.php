<?php

namespace Tests\Support;

/**
 * EVERY rate limit of the API in one table (docs/phase-7-design.md 3.5). RateLimitSweepTest proves each row (max calls pass, max+1 is a 429 with
 * Retry-After and the standard body, an independent bucket for another user or IP, the window passes, a wrong role gets 403 not 429) and fails when a
 * `throttle:` name on any route is missing here.
 *
 * name     limiter name (`named` false = a counter kept by the controller, not a middleware)
 * as       the caller: customer, staff, admin or guest; `pool` callers of that role take turns (an IP-keyed limit needs several users)
 * wrong    a role that must get 403 (never 429) from the same route, if any
 * max      calls that pass; the next one is 429
 * window   seconds until the bucket is free again
 * burst    another limiter on the route allows only this many calls per minute: wait a minute after every `burst` calls (hourly limits)
 * bucket   user = another user has an independent bucket, ip = another REMOTE_ADDR has
 * call     closure(callIndex, test): the request (the sweep has already authenticated the caller)
 */
class RateLimits
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $get = fn (string $uri) => fn (int $i, $t) => $t->getJson($uri);
        $delete = fn (string $uri) => fn (int $i, $t) => $t->deleteJson($uri);
        $post = fn (string $uri, array $body = []) => fn (int $i, $t) => $t->postJson($uri, $body);

        return [
            ['name' => 'api', 'label' => 'api (guest)', 'as' => 'guest', 'max' => 60, 'window' => 60, 'bucket' => 'ip', 'call' => $get('/api/v1/health')],
            ['name' => 'api', 'label' => 'api (customer)', 'as' => 'customer', 'max' => 60, 'window' => 60, 'bucket' => 'user', 'call' => $get('/api/v1/me')],
            ['name' => 'api', 'label' => 'api (staff)', 'as' => 'staff', 'max' => 240, 'window' => 60, 'bucket' => 'user', 'call' => $get('/api/v1/me')],
            // Readiness probe: its own bucket, the guest `api` bucket is not touched (HealthReadyTest).
            ['name' => 'health-ready', 'as' => 'guest', 'max' => 120, 'window' => 60, 'bucket' => 'ip', 'call' => $get('/api/v1/health/ready')],
            ['name' => 'catalog-search', 'as' => 'customer', 'max' => 40, 'window' => 60, 'bucket' => 'user', 'call' => $get('/api/v1/products?q=62')],
            ['name' => 'order-search', 'label' => 'order-search (customer)', 'as' => 'customer', 'max' => 30, 'window' => 60, 'bucket' => 'user', 'call' => $get('/api/v1/orders?q=PO')],
            ['name' => 'order-search', 'label' => 'order-search (staff)', 'as' => 'staff', 'max' => 60, 'window' => 60, 'bucket' => 'user', 'call' => $get('/api/v1/orders?q=PO')],
            // Quotes and invoices share the bucket of the order search (one `order-search:<user>` key).
            ['name' => 'order-search', 'label' => 'order-search (GET /quotes, customer)', 'as' => 'customer', 'max' => 30, 'window' => 60, 'bucket' => 'user', 'call' => $get('/api/v1/quotes?q=QUO')],
            ['name' => 'order-search', 'label' => 'order-search (GET /quotes, staff)', 'as' => 'staff', 'max' => 60, 'window' => 60, 'bucket' => 'user', 'call' => $get('/api/v1/quotes?q=QUO')],
            ['name' => 'order-search', 'label' => 'order-search (GET /invoices, customer)', 'as' => 'customer', 'max' => 30, 'window' => 60, 'bucket' => 'user', 'call' => $get('/api/v1/invoices?q=INV')],
            ['name' => 'order-search', 'label' => 'order-search (GET /invoices, staff)', 'as' => 'staff', 'max' => 60, 'window' => 60, 'bucket' => 'user', 'call' => $get('/api/v1/invoices?q=INV')],
            ['name' => 'orders-reorder', 'as' => 'customer', 'wrong' => 'staff', 'max' => 10, 'window' => 60, 'bucket' => 'user', 'call' => $post('/api/v1/orders/ORD-AAAAA-AAAAA/reorder')],
            ['name' => 'orders-create', 'label' => 'orders-create (POST /orders)', 'as' => 'customer', 'wrong' => 'staff', 'max' => 10, 'window' => 60, 'bucket' => 'user', 'call' => $post('/api/v1/orders')],
            ['name' => 'orders-create', 'label' => 'orders-create (POST /quotes/{n}/accept)', 'as' => 'customer', 'wrong' => 'staff', 'max' => 10, 'window' => 60, 'bucket' => 'user', 'call' => $post('/api/v1/quotes/QUO-AAAAA-AAAAA/accept')],
            ['name' => 'quotes-create', 'as' => 'customer', 'wrong' => 'staff', 'max' => 10, 'window' => 60, 'bucket' => 'user', 'call' => $post('/api/v1/quotes')],
            ['name' => 'quote-uploads', 'as' => 'customer', 'max' => 20, 'window' => 60, 'bucket' => 'user', 'call' => $post('/api/v1/quotes/QUO-AAAAA-AAAAA/attachments')],
            ['name' => 'quote-attachment-delete', 'label' => 'quote-attachment-delete (customer)', 'as' => 'customer', 'max' => 30, 'window' => 60, 'bucket' => 'user', 'call' => $delete('/api/v1/quotes/QUO-AAAAA-AAAAA/attachments/00000000-0000-4000-8000-000000000000')],
            ['name' => 'quote-attachment-delete', 'label' => 'quote-attachment-delete (staff)', 'as' => 'staff', 'max' => 30, 'window' => 60, 'bucket' => 'user', 'call' => $delete('/api/v1/quotes/QUO-AAAAA-AAAAA/attachments/00000000-0000-4000-8000-000000000000')],
            ['name' => 'quote-uploads-hourly', 'as' => 'customer', 'max' => 120, 'window' => 3600, 'burst' => 20, 'bucket' => 'user', 'call' => $post('/api/v1/quotes/QUO-AAAAA-AAAAA/attachments')],
            ['name' => 'invoice-pdf', 'as' => 'customer', 'max' => 20, 'window' => 60, 'bucket' => 'user', 'call' => $get('/api/v1/invoices/00000000-0000-4000-8000-000000000000/pdf')],
            ['name' => 'invoice-pdf-ip', 'as' => 'customer', 'pool' => 4, 'max' => 60, 'window' => 60, 'bucket' => 'ip', 'call' => $get('/api/v1/invoices/00000000-0000-4000-8000-000000000000/pdf')],
            ['name' => 'invoices-write', 'as' => 'staff', 'wrong' => 'customer', 'max' => 30, 'window' => 60, 'bucket' => 'user', 'call' => $post('/api/v1/invoices/00000000-0000-4000-8000-000000000000/void', ['reason' => 'x'])],
            ['name' => 'admin-writes', 'as' => 'staff', 'wrong' => 'customer', 'max' => 60, 'window' => 60, 'bucket' => 'user', 'call' => $post('/api/v1/admin/products')],
            ['name' => 'admin-import', 'as' => 'staff', 'wrong' => 'customer', 'max' => 5, 'window' => 60, 'bucket' => 'user', 'call' => $post('/api/v1/admin/products/imports')],
            ['name' => 'admin-import-hourly', 'as' => 'staff', 'wrong' => 'customer', 'max' => 20, 'window' => 3600, 'burst' => 5, 'bucket' => 'user', 'call' => $post('/api/v1/admin/products/imports')],
            ['name' => 'admin-invites', 'as' => 'admin', 'wrong' => 'staff', 'max' => 20, 'window' => 3600, 'bucket' => 'user', 'call' => $post('/api/v1/admin/users')],
            ['name' => 'login-ip', 'as' => 'guest', 'max' => 20, 'window' => 60, 'bucket' => 'ip', 'call' => fn (int $i, $t) => $t->postJson('/api/v1/auth/login', ['email' => "ghost{$i}@nowhere.test", 'password' => 'wrong-password-1'])],
            ['name' => 'password-reset', 'label' => 'password-reset (forgot-password)', 'as' => 'guest', 'max' => 5, 'window' => 60, 'bucket' => 'ip', 'call' => $post('/api/v1/auth/forgot-password', ['email' => 'ghost@nowhere.test'])],
            ['name' => 'password-reset', 'label' => 'password-reset (reset-password)', 'as' => 'guest', 'max' => 5, 'window' => 60, 'bucket' => 'ip', 'call' => $post('/api/v1/auth/reset-password')],
            ['name' => 'password-reset', 'label' => 'password-reset (accept-invitation)', 'as' => 'guest', 'max' => 5, 'window' => 60, 'bucket' => 'ip', 'call' => $post('/api/v1/auth/accept-invitation')],
            // Kept by the login controller itself: 5 failures a minute per email and IP, 20 failures in 15 minutes per email whatever the IP.
            ['name' => 'login-email-ip', 'named' => false, 'as' => 'guest', 'max' => 5, 'window' => 60, 'bucket' => 'none', 'call' => fn (int $i, $t) => $t->postJson('/api/v1/auth/login', ['email' => 'victim@nowhere.test', 'password' => 'wrong-password-1'])],
            ['name' => 'login-email', 'named' => false, 'as' => 'guest', 'max' => 20, 'window' => 900, 'bucket' => 'none', 'spread_ips' => true,
                'call' => fn (int $i, $t) => $t->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.(10 + $i)])->postJson('/api/v1/auth/login', ['email' => 'victim@nowhere.test', 'password' => 'wrong-password-1'])],
        ];
    }

    /** @return list<string> the names that must resolve through RateLimiter::limiter() */
    public static function names(): array
    {
        return array_values(array_unique(array_column(array_filter(self::all(), fn ($e) => ($e['named'] ?? true)), 'name')));
    }
}
