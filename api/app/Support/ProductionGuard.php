<?php

namespace App\Support;

/**
 * Refuses an unsafe configuration in every environment except `local`, `testing` and `loadtest` (compared trimmed and case-insensitive, so
 * `prod`, `live` and `Production` are production). A pure function over a settings array, so every rule is a unit test
 * (tests/Unit/ProductionGuardTest.php). It reports rule names (the environment variable that must change), never values:
 * a value in a log or a console line may be a secret.
 *
 * Enforced two ways: EnforceProductionConfig answers every HTTP request with 500 while a rule fails, and `php artisan portal:check-config`
 * is the deploy gate. Console commands are never blocked (image builds run `artisan package:discover`).
 */
class ProductionGuard
{
    /** The only environments the production rules (and HSTS) skip. Everything else, an unknown or empty name included, is production. */
    public const EXEMPT_ENVIRONMENTS = ['local', 'testing', 'loadtest'];

    /** @return array<string, mixed> the settings the rules read, taken from the live configuration */
    public static function settings(): array
    {
        return [
            'APP_DEBUG' => config('app.debug'),
            'APP_URL' => config('app.url'),
            'APP_URL_WEB' => config('app.web_url'),
            'SESSION_SECURE_COOKIE' => config('session.secure'),
            'SESSION_DRIVER' => config('session.driver'),
            'CACHE_STORE' => config('cache.default'),
            'CORS_ALLOWED_ORIGINS' => config('cors.allowed_origins'),
            'SANCTUM_STATEFUL_DOMAINS' => config('sanctum.stateful'),
            'TRUSTED_HOSTS' => config('portal.trusted_hosts'),
            'TRUSTED_PROXIES' => config('portal.trusted_proxies'),
            'TRUSTED_PROXIES_NONE' => config('portal.trusted_proxies_none'),
            'LOG_LEVEL' => config('portal.log_level'),
            'MAIL_MAILER' => config('mail.default'),
            'QUEUE_CONNECTION' => config('queue.default'),
            'INVOICING_SELLER_NAME' => config('invoicing.seller.name'),
        ];
    }

    public static function applies(): bool
    {
        return ! in_array(strtolower(trim((string) app()->environment())), self::EXEMPT_ENVIRONMENTS, true);
    }

    /**
     * @param  array<string, mixed>  $s  see settings()
     * @return array{errors: list<string>, warnings: list<string>} environment variable names
     */
    public static function check(array $s): array
    {
        $errors = [];
        $warnings = [];
        $s += array_fill_keys(array_keys(self::settings()), null);

        $s['APP_DEBUG'] === false || $errors[] = 'APP_DEBUG';
        self::scheme($s['APP_URL']) === 'https' || $errors[] = 'APP_URL';

        $web = self::origin($s['APP_URL_WEB']);
        ($web !== null && $web['scheme'] === 'https' && ! self::local($web['host'])) || $errors[] = 'APP_URL_WEB';

        $s['SESSION_SECURE_COOKIE'] === true || $errors[] = 'SESSION_SECURE_COOKIE';
        $s['SESSION_DRIVER'] !== 'array' || $errors[] = 'SESSION_DRIVER';

        match ($s['CACHE_STORE']) {
            'array' => $errors[] = 'CACHE_STORE',
            // The rate limiters count in this store: file is not atomic under several PHP workers.
            'file' => $warnings[] = 'CACHE_STORE',
            default => null,
        };

        $origins = is_array($s['CORS_ALLOWED_ORIGINS']) ? $s['CORS_ALLOWED_ORIGINS'] : [];
        if ($origins === [] || array_filter($origins, fn ($o) => ! is_string($o) || ! self::httpsOrigin($o))
            || ($web !== null && ! in_array($web['scheme'].'://'.$web['authority'], $origins, true))) {
            $errors[] = 'CORS_ALLOWED_ORIGINS';
        }

        $stateful = is_array($s['SANCTUM_STATEFUL_DOMAINS']) ? array_map('trim', $s['SANCTUM_STATEFUL_DOMAINS']) : [];
        if (in_array('', $stateful, true) || array_filter($stateful, fn ($d) => str_contains($d, '*')) || ($web !== null && ! in_array($web['authority'], $stateful, true))) {
            $errors[] = 'SANCTUM_STATEFUL_DOMAINS';
        }

        ($s['TRUSTED_HOSTS'] ?? []) !== [] || $errors[] = 'TRUSTED_HOSTS';
        // Behind a proxy with no list, every visitor shares the proxy's IP and so one login and one guest bucket. Only an explicit
        // "clients reach the app directly" (TRUSTED_PROXIES_NONE=true) allows an empty list.
        ($s['TRUSTED_PROXIES'] ?? []) !== [] || $s['TRUSTED_PROXIES_NONE'] === true || $errors[] = 'TRUSTED_PROXIES';

        strtolower((string) $s['LOG_LEVEL']) !== 'debug' || $errors[] = 'LOG_LEVEL';
        ! in_array($s['MAIL_MAILER'], ['log', 'array'], true) || $errors[] = 'MAIL_MAILER';
        $s['QUEUE_CONNECTION'] !== 'sync' || $warnings[] = 'QUEUE_CONNECTION';

        // Issuing is refused anyway while the seller is a placeholder (InvoiceSnapshotBuilder): a warning here, not an outage.
        stripos((string) $s['INVOICING_SELLER_NAME'], 'PLACEHOLDER') === false || $warnings[] = 'INVOICING_SELLER_NAME';

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private static function scheme(mixed $url): ?string
    {
        return is_string($url) ? strtolower((string) parse_url($url, PHP_URL_SCHEME)) : null;
    }

    /** @return array{scheme: string, host: string, authority: string}|null authority = host[:port], the form Sanctum's stateful list uses */
    private static function origin(mixed $url): ?array
    {
        $parts = is_string($url) && $url !== '' ? parse_url($url) : false;

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return [
            'scheme' => strtolower($parts['scheme']),
            'host' => strtolower($parts['host']),
            'authority' => strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : ''),
        ];
    }

    private static function httpsOrigin(string $origin): bool
    {
        $o = self::origin($origin);

        return $o !== null && $o['scheme'] === 'https' && ! str_contains($origin, '*') && $origin === 'https://'.$o['authority'];
    }

    private static function local(string $host): bool
    {
        return $host === 'localhost' || $host === '[::1]' || str_ends_with($host, '.localhost') || str_starts_with($host, '127.') || $host === '0.0.0.0';
    }
}
