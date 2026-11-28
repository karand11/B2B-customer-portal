<?php

namespace App\Providers;

use App\Models\AuditLogEntry;
use App\Models\QuoteRequest;
use App\Policies\AuditLogPolicy;
use App\Policies\QuotePolicy;
use App\Support\ClientIp;
use App\Support\FailOpenPwnedVerifier;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The framework verifier waits 30 s and logs the exception (its message contains the hash prefix). Ours waits 2 s and fails open.
        // extend(), not bind(): the framework registers its own binding lazily (deferred provider) and would overwrite ours.
        $this->app->extend(UncompromisedVerifier::class, fn ($verifier, $app) => new FailOpenPwnedVerifier($app[HttpFactory::class]));
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

        // The audit log model is AuditLogEntry, so its policy name cannot be guessed either.
        Gate::policy(AuditLogEntry::class, AuditLogPolicy::class);

        $this->rateLimiters();
        $this->observability();
    }

    /**
     * IPv6 clients are keyed on their /64 so rotating addresses inside one prefix does not dodge limits.
     * Back-office pages fire several requests per click: staff and admin get 240 per minute in their own per-user bucket, everyone else 60.
     * The table of every limiter lives in tests/Support/RateLimits.php (RateLimitSweepTest proves each row).
     */
    private function rateLimiters(): void
    {
        $limits = config('portal.limits');

        RateLimiter::for('api', fn (Request $request) => $this->limit($this->isStaffSession($request) ? 240 : 60, $request->user()?->id ?: ClientIp::key($request->ip())));
        // Heaviest read of the catalog: 40 a minute per user, on top of `api`. Filters, detail and categories are not covered.
        RateLimiter::for('catalog-search', fn (Request $request) => $this->limit($limits['catalog_search_per_minute'], 'catalog-search:'.$this->who($request)));
        // Readiness probe of the load balancer: generous (two nodes probing every second fit) and separate from the guest `api` bucket.
        RateLimiter::for('health-ready', fn (Request $request) => $this->limit(120, 'health-ready:'.ClientIp::key($request->ip())));
        // Search (the `q` parameter) of the order, quote and invoice lists, one shared bucket per user: 30 a minute for customers, 60 for staff.
        // A list without `q` is not counted.
        RateLimiter::for('order-search', fn (Request $request) => $request->filled('q')
            ? $this->limit($this->isStaffSession($request) ? $limits['order_search_staff_per_minute'] : $limits['order_search_per_minute'], 'order-search:'.$this->who($request))
            : Limit::none());
        // Back-office writes (every attempt counts, 422s too), CSV imports (5 a minute, 20 an hour) and invitation mails (20 an hour), all per user.
        RateLimiter::for('admin-writes', fn (Request $request) => $this->limit(60, 'admin-writes:'.$this->who($request)));
        RateLimiter::for('admin-import', fn (Request $request) => $this->limit(5, 'admin-import:'.$this->who($request)));
        RateLimiter::for('admin-import-hourly', fn (Request $request) => $this->limit(20, 'admin-import-hourly:'.$this->who($request), 60));
        RateLimiter::for('admin-invites', fn (Request $request) => $this->limit(20, 'admin-invites:'.$this->who($request), 60));
        // Every POST /orders and POST /quotes/{n}/accept attempt counts (one shared bucket), including 422s (the routes sit behind auth, so the user id key always exists).
        RateLimiter::for('orders-create', fn (Request $request) => $this->limit(10, 'orders-create:'.$this->who($request)));
        RateLimiter::for('orders-reorder', fn (Request $request) => $this->limit($limits['orders_reorder_per_minute'], 'orders-reorder:'.$this->who($request)));
        // Every POST /quotes and every upload attempt counts, including 422s.
        RateLimiter::for('quotes-create', fn (Request $request) => $this->limit(10, 'quotes-create:'.$this->who($request)));
        RateLimiter::for('quote-uploads', fn (Request $request) => $this->limit(20, 'quote-uploads:'.$this->who($request)));
        RateLimiter::for('quote-attachment-delete', fn (Request $request) => $this->limit(30, 'quote-attachment-delete:'.$this->who($request)));
        RateLimiter::for('quote-uploads-hourly', fn (Request $request) => $this->limit(config('quotes.uploads_per_hour'), 'quote-uploads-hourly:'.$this->who($request), 60));
        // PDF rendering is CPU heavy: every request that reaches it counts (404s too). The three staff writes share one budget; every attempt counts.
        RateLimiter::for('invoice-pdf', fn (Request $request) => $this->limit(20, 'invoice-pdf:'.$this->who($request)));
        RateLimiter::for('invoice-pdf-ip', fn (Request $request) => $this->limit(60, 'invoice-pdf-ip:'.ClientIp::key($request->ip())));
        RateLimiter::for('invoices-write', fn (Request $request) => $this->limit(30, 'invoices-write:'.$this->who($request)));
        RateLimiter::for('login-ip', fn (Request $request) => $this->limit(20, ClientIp::key($request->ip())));
        RateLimiter::for('password-reset', fn (Request $request) => $this->limit(5, ClientIp::key($request->ip())));
    }

    /** Slow query and failed job logging. Never bindings, never exception messages (both can contain personal data). */
    private function observability(): void
    {
        // The threshold is read per query (cheap) so it can change at runtime and in tests; 0 disables.
        DB::listen(function (QueryExecuted $query) {
            $slow = (int) config('portal.slow_query_ms');
            $slow > 0 && $query->time >= $slow && Log::warning('Slow query', ['ms' => round($query->time, 1), 'sql' => $query->sql, 'connection' => $query->connectionName]);
        });

        Event::listen(JobFailed::class, fn (JobFailed $event) => Log::error('Queue job failed', [
            'job' => $event->job->resolveName(), 'queue' => $event->job->getQueue(), 'attempts' => $event->job->attempts(), 'exception' => $event->exception::class,
        ]));
        Event::listen(QueueBusy::class, fn (QueueBusy $event) => Log::warning('Queue backlog', ['queue' => $event->queue, 'size' => $event->size]));
    }

    /** The role stored at login (see AuthController::login): no query, and never stale because a role change ends the session. */
    private function isStaffSession(Request $request): bool
    {
        return $request->hasSession() && in_array($request->session()->get('role'), ['staff', 'admin'], true);
    }

    private function who(Request $request): string|int
    {
        return $request->user()?->id ?: ClientIp::key($request->ip());
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
