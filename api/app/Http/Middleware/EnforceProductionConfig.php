<?php

namespace App\Http\Middleware;

use App\Support\LogThrottle;
use App\Support\ProductionGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Outside local, testing and loadtest an unsafe configuration (ProductionGuard) answers every request with a generic 500 and a (throttled) log line naming the rules. */
class EnforceProductionConfig
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ProductionGuard::applies() && ($errors = ProductionGuard::check(ProductionGuard::settings())['errors']) !== []) {
            // One line per rule set and minute: during an outage every request would write one.
            LogThrottle::allow('production-config:'.md5(implode(',', $errors))) && Log::critical('Unsafe production configuration: the request was refused.', ['rules' => $errors]);

            return response()->json(['message' => 'Server Error'], 500);
        }

        return $next($request);
    }
}
