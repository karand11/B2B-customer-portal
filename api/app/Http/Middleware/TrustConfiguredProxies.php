<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Trusts the proxies of config('portal.trusted_proxies') (TRUSTED_PROXIES), read per request so a config:cache build and a test can both set it.
 * Only X-Forwarded-For, -Proto and -Port are honoured: -Host and -Prefix never change the host or the URLs of the app.
 * An empty list trusts nobody; the framework's "trust the caller" wildcard cannot be reached because the list is validated at boot.
 */
class TrustConfiguredProxies extends TrustProxies
{
    protected function proxies()
    {
        return config('portal.trusted_proxies') ?: null;
    }

    protected function headers()
    {
        return Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT;
    }
}
