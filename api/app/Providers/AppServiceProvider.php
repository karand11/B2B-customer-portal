<?php

namespace App\Providers;

use App\Models\QuoteRequest;
use App\Policies\QuotePolicy;
use App\Support\ClientIp;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        // The model is QuoteRequest, so the policy name cannot be guessed.
        Gate::policy(QuoteRequest::class, QuotePolicy::class);

        // IPv6 clients are keyed on their /64 so rotating addresses inside one prefix does not dodge limits.
        RateLimiter::for('api', fn (Request $request) => $this->limit(60, $request->user()?->id ?: ClientIp::key($request->ip())));
        // Every POST /orders attempt counts, including 422s (the route sits behind auth, so the user id key always exists).
        RateLimiter::for('orders-create', fn (Request $request) => $this->limit(10, 'orders-create:'.($request->user()?->id ?: ClientIp::key($request->ip()))));
        // Every POST /quotes and every upload attempt counts, including 422s.
        RateLimiter::for('quotes-create', fn (Request $request) => $this->limit(10, 'quotes-create:'.($request->user()?->id ?: ClientIp::key($request->ip()))));
        RateLimiter::for('quote-uploads', fn (Request $request) => $this->limit(20, 'quote-uploads:'.($request->user()?->id ?: ClientIp::key($request->ip()))));
        RateLimiter::for('quote-uploads-hourly', fn (Request $request) => $this->limit(
            config('quotes.uploads_per_hour'), 'quote-uploads-hourly:'.($request->user()?->id ?: ClientIp::key($request->ip())), 60,
        ));
        RateLimiter::for('login-ip', fn (Request $request) => $this->limit(20, ClientIp::key($request->ip())));
        RateLimiter::for('password-reset', fn (Request $request) => $this->limit(5, ClientIp::key($request->ip())));
    }

    private function limit(int $max, string|int $key, int $minutes = 1): Limit
    {
        return Limit::perMinutes($minutes, $max)->by($key)->response(
            fn (Request $request, array $headers) => response()->json([
                'message' => "Too many attempts. Please try again in {$headers['Retry-After']} seconds.",
            ], 429, $headers)
        );
    }
}
