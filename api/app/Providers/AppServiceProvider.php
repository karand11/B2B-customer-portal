<?php

namespace App\Providers;

use App\Support\ClientIp;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Any N+1 throws in tests and dev instead of silently degrading.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Reset links point at the SPA, never at the API host.
        ResetPassword::createUrlUsing(fn ($user, string $token) => config('app.web_url')
            .'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset()));

        // IPv6 clients are keyed on their /64 so rotating addresses inside one prefix does not dodge limits.
        RateLimiter::for('api', fn (Request $request) => $this->limit(60, $request->user()?->id ?: ClientIp::key($request->ip())));
        RateLimiter::for('login-ip', fn (Request $request) => $this->limit(20, ClientIp::key($request->ip())));
        RateLimiter::for('password-reset', fn (Request $request) => $this->limit(5, ClientIp::key($request->ip())));
    }

    private function limit(int $perMinute, string|int $key): Limit
    {
        return Limit::perMinute($perMinute)->by($key)->response(
            fn (Request $request, array $headers) => response()->json([
                'message' => "Too many attempts. Please try again in {$headers['Retry-After']} seconds.",
            ], 429, $headers)
        );
    }
}
