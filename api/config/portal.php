<?php

use App\Support\TrustedHosts;
use App\Support\TrustedProxies;

/*
 * Phase 7 knobs. Every new environment variable of the hardening pass is read here (env() is only valid in config files because of config:cache).
 * Spec: docs/phase-7-design.md.
 */

$int = fn (string $key, int $default): int => (int) env($key, $default);

// A bad TRUSTED_HOSTS stops the boot like a bad TRUSTED_PROXIES, except for `portal:check-config`, which must run to report it
// (the list is then empty, which the guard reports as TRUSTED_HOSTS in production).
try {
    $trustedHosts = TrustedHosts::fromEnvString(env('TRUSTED_HOSTS'));
} catch (InvalidArgumentException $e) {
    TrustedHosts::runningCheckConfig() ? $trustedHosts = [] : throw $e;
}

return [

    // Comma separated IPs or CIDRs of the reverse proxies in front of the app. Empty = trust nobody. `*` makes boot fail (TrustedProxies).
    'trusted_proxies' => TrustedProxies::fromEnvString(env('TRUSTED_PROXIES')),

    // Set to true ONLY when clients reach the app directly, with no reverse proxy or load balancer in front: every non-local environment
    // must either list its proxies or say so (ProductionGuard). Without a list behind a proxy, all visitors would share the proxy's IP.
    'trusted_proxies_none' => filter_var(env('TRUSTED_PROXIES_NONE', false), FILTER_VALIDATE_BOOL),

    // Comma separated exact host names only (no scheme, port, path or wildcard: TrustedHosts throws at boot). Empty = every Host is accepted
    // (local development); required in every environment except local, testing and loadtest.
    'trusted_hosts' => $trustedHosts,

    // Strict-Transport-Security max-age in seconds (secure requests outside local, testing and loadtest only). 0 disables the header.
    'hsts_max_age' => $int('PORTAL_HSTS_MAX_AGE', 31536000),

    // Result window of every list endpoint: page * per_page may not exceed it.
    'max_window' => $int('PORTAL_MAX_WINDOW', 10000),

    'limits' => [
        'catalog_search_per_minute' => $int('PORTAL_CATALOG_SEARCH_PER_MINUTE', 40),
        'order_search_per_minute' => $int('PORTAL_ORDER_SEARCH_PER_MINUTE', 30),
        'order_search_staff_per_minute' => $int('PORTAL_ORDER_SEARCH_STAFF_PER_MINUTE', 60),
        'orders_reorder_per_minute' => $int('PORTAL_ORDERS_REORDER_PER_MINUTE', 10),
    ],

    // Have I Been Pwned range API (only the first five characters of the SHA-1 leave the server). Fails OPEN on any outage.
    // Off in local and testing unless AUTH_PASSWORD_BREACH_CHECK says otherwise.
    'password_breach_check' => filter_var(
        env('AUTH_PASSWORD_BREACH_CHECK', ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
        FILTER_VALIDATE_BOOL,
    ),
    'password_breach_timeout' => 2,

    // Log a warning for every query slower than this many milliseconds (SQL with placeholders only, never bindings). 0 disables.
    'slow_query_ms' => $int('PORTAL_SLOW_QUERY_MS', 500),

    // quotes:storage-check warns below this much free space on the storage volume (5 GiB).
    'storage_min_free_bytes' => $int('PORTAL_STORAGE_MIN_FREE_BYTES', 5 * 1024 * 1024 * 1024),

    // Read by ProductionGuard only (the rule says LOG_LEVEL must not be debug in production and staging).
    'log_level' => (string) env('LOG_LEVEL', 'debug'),

    // Only `local`, `testing` and `loadtest` may run portal:loadtest-seed; the password of the generated load users comes from here.
    'loadtest_password' => env('LOADTEST_PASSWORD'),

];
