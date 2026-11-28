<?php

namespace App\Http\Middleware;

use App\Support\ProductionGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The outermost global middleware: whatever happens inside (a 401, a 404, a 413, a 419, a 500, a CORS preflight) the response leaves with
 * the API security headers. Spec: docs/api-contract.md "Phase 7", "Response headers".
 */
class SecurityHeaders
{
    public const PERMISSIONS_POLICY = 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        // `origin`, never `no-referrer`: Sanctum decides statefulness from Origin or Referer (docs/api-contract.md, Phase 6 token hygiene).
        $headers->set('Referrer-Policy', 'origin');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);
        $headers->set('Cross-Origin-Resource-Policy', 'same-site');

        // The attachment download sends its own (sandbox) policy.
        $headers->has('Content-Security-Policy') || $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        if ($request->is('api/*', 'sanctum/*') && ! str_contains((string) $headers->get('Cache-Control'), 'no-store')) {
            $headers->set('Cache-Control', 'no-store');
        }

        $maxAge = (int) config('portal.hsts_max_age');
        if ($maxAge > 0 && $request->isSecure() && ProductionGuard::applies()) {
            $headers->set('Strict-Transport-Security', 'max-age='.$maxAge);
        }

        $headers->remove('X-Powered-By');
        $headers->remove('X-XSS-Protection');
        // PHP's own X-Powered-By (expose_php) is not part of the response object; the real fix is expose_php=Off in php.ini.
        header_remove('X-Powered-By');

        return $response;
    }
}
