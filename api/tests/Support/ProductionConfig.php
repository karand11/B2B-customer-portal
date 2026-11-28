<?php

namespace Tests\Support;

/** A configuration that passes every ProductionGuard rule, so a test can flip the app into production or staging and change ONE thing. */
class ProductionConfig
{
    public const HOST = 'api.portal.example';

    public const WEB = 'https://portal.example';

    /** @return array<string, mixed> the guard settings of a safe production setup */
    public static function safeSettings(): array
    {
        return [
            'APP_DEBUG' => false, 'APP_URL' => 'https://'.self::HOST, 'APP_URL_WEB' => self::WEB, 'SESSION_SECURE_COOKIE' => true,
            'SESSION_DRIVER' => 'database', 'CACHE_STORE' => 'database', 'CORS_ALLOWED_ORIGINS' => [self::WEB], 'SANCTUM_STATEFUL_DOMAINS' => ['portal.example'],
            'TRUSTED_HOSTS' => [self::HOST], 'TRUSTED_PROXIES' => ['10.0.0.0/8'], 'TRUSTED_PROXIES_NONE' => false, 'LOG_LEVEL' => 'info', 'MAIL_MAILER' => 'smtp', 'QUEUE_CONNECTION' => 'database',
            'INVOICING_SELLER_NAME' => 'Real Seller GmbH',
        ];
    }

    /**
     * A safe production (or staging) setup for tests that keep sending requests to the default host and SPA origin: the guard stays satisfied
     * (host list and stateful domain are the test client's), so only the environment-specific behaviour under test changes.
     */
    public static function applyForTestClient(string $env): void
    {
        self::apply($env, ['portal.trusted_hosts' => ['localhost'], 'portal.trusted_proxies' => [], 'portal.trusted_proxies_none' => true, 'cors.allowed_origins' => [self::WEB]]);
        test()->withHeaders(['Origin' => self::WEB]);
    }

    /** Make the running application a safe production (or staging, or any other non-exempt environment) one; $override replaces config values by their dotted key. */
    public static function apply(string $env = 'production', array $override = []): void
    {
        app()->detectEnvironment(fn () => $env);

        config(array_replace([
            'app.debug' => false, 'app.url' => 'https://'.self::HOST, 'app.web_url' => self::WEB, 'session.secure' => true, 'session.driver' => 'database',
            'cache.default' => 'database', 'cors.allowed_origins' => [self::WEB], 'sanctum.stateful' => ['portal.example'],
            'portal.trusted_hosts' => [self::HOST], 'portal.trusted_proxies' => ['10.0.0.0/8'], 'portal.trusted_proxies_none' => false, 'portal.log_level' => 'info', 'mail.default' => 'smtp',
            'queue.default' => 'database',
        ], $override));
    }
}
