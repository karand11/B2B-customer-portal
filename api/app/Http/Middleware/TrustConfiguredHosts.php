<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;

/**
 * Accepts only the Host headers of config('portal.trusted_hosts') (TRUSTED_HOSTS, exact names, no subdomains). An empty list accepts every host
 * (local development). The framework's own version never runs in tests and in `local`, and its static state would outlive a test: this one
 * sets (or clears) the list on every request. A foreign host raises SuspiciousOperationException, rendered as a generic 400 (bootstrap/app.php).
 */
class TrustConfiguredHosts extends TrustHosts
{
    public function handle(Request $request, $next)
    {
        Request::setTrustedHosts(array_map(fn (string $host) => '^'.preg_quote($host, '#').'$', config('portal.trusted_hosts')));

        return $next($request);
    }
}
