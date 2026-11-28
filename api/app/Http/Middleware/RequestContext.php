<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id: the inbound X-Request-Id when it is a plain token of 8 to 64 safe characters, otherwise a fresh UUID.
 * The id goes into the log Context (so every log record of the request and of the jobs it queues carries it) and back on the response,
 * error responses included. Anything else a client sends in that header is dropped, never logged.
 */
class RequestContext
{
    public const HEADER = 'X-Request-Id';

    public const PATTERN = '/^[A-Za-z0-9._-]{8,64}$/D';

    public function handle(Request $request, Closure $next): Response
    {
        $inbound = $request->headers->get(self::HEADER);
        $id = is_string($inbound) && preg_match(self::PATTERN, $inbound) === 1 ? $inbound : (string) Str::uuid();

        // Context is scoped to the app instance: a request must never inherit the previous request's user.
        Context::flush();
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
