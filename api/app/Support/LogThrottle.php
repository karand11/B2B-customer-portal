<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/** One log line per key per minute: a failure that repeats on every request or probe must not flood the log during an outage. */
class LogThrottle
{
    public static function allow(string $key): bool
    {
        try {
            return Cache::add('log-throttle:'.$key, 1, 60);
        } catch (Throwable) {
            // Fail open: the cache is often the very thing that is down, and a missing log line is worse than a repeated one.
            return true;
        }
    }
}
