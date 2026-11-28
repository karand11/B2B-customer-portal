<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\LogThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Readiness for a load balancer: can this node reach its database and its cache right now? The body never says which check failed
 * (a public route must not describe the infrastructure); the log line does. Queue and scheduler are deliberately not checked.
 */
class HealthReadyController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => fn () => DB::select('select 1') !== [],
            'cache' => function () {
                $token = bin2hex(random_bytes(8));
                Cache::put('health:ready', $token, 60);

                return Cache::get('health:ready') === $token;
            },
        ];

        foreach ($checks as $name => $check) {
            try {
                $ok = $check();
            } catch (Throwable) {
                $ok = false;
            }

            if (! $ok) {
                // A probe every few seconds from several nodes would flood the log: one line per failing check and minute.
                LogThrottle::allow('ready:'.$name) && Log::error('Readiness check failed', ['check' => $name]);

                return response()->json(['status' => 'unavailable'], 503);
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
