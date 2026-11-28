<?php

use App\Support\ProductionGuard;
use Tests\Support\ProductionConfig;

/** The safe settings with some values replaced. */
function guardSettings(array $override = []): array
{
    return array_replace(ProductionConfig::safeSettings(), $override);
}

it('accepts a safe production configuration without warnings', function () {
    expect(ProductionGuard::check(guardSettings()))->toBe(['errors' => [], 'warnings' => []]);
});

it('reports each unsafe setting by the name of its environment variable', function (array $override, string|array $rules) {
    expect(ProductionGuard::check(guardSettings($override))['errors'])->toBe((array) $rules);
})->with([
    'debug on' => [['APP_DEBUG' => true], 'APP_DEBUG'],
    'debug missing' => [['APP_DEBUG' => null], 'APP_DEBUG'],
    'http app url' => [['APP_URL' => 'http://api.portal.example'], 'APP_URL'],
    'no app url' => [['APP_URL' => null], 'APP_URL'],
    'web url missing' => [['APP_URL_WEB' => ''], 'APP_URL_WEB'],
    'web url null' => [['APP_URL_WEB' => null], 'APP_URL_WEB'],
    // An http web origin is wrong twice: the URL itself and the (necessarily http) CORS entry that allows it.
    'web url http' => [['APP_URL_WEB' => 'http://portal.example', 'CORS_ALLOWED_ORIGINS' => ['http://portal.example']], ['APP_URL_WEB', 'CORS_ALLOWED_ORIGINS']],
    'web url localhost' => [['APP_URL_WEB' => 'https://localhost', 'CORS_ALLOWED_ORIGINS' => ['https://localhost'], 'SANCTUM_STATEFUL_DOMAINS' => ['localhost']], 'APP_URL_WEB'],
    'web url loopback' => [['APP_URL_WEB' => 'https://127.0.0.1', 'CORS_ALLOWED_ORIGINS' => ['https://127.0.0.1'], 'SANCTUM_STATEFUL_DOMAINS' => ['127.0.0.1']], 'APP_URL_WEB'],
    'web url garbage' => [['APP_URL_WEB' => 'portal.example'], 'APP_URL_WEB'],
    'insecure cookie' => [['SESSION_SECURE_COOKIE' => false], 'SESSION_SECURE_COOKIE'],
    'cookie unset' => [['SESSION_SECURE_COOKIE' => null], 'SESSION_SECURE_COOKIE'],
    'array sessions' => [['SESSION_DRIVER' => 'array'], 'SESSION_DRIVER'],
    'array cache' => [['CACHE_STORE' => 'array'], 'CACHE_STORE'],
    'cors star' => [['CORS_ALLOWED_ORIGINS' => ['*']], 'CORS_ALLOWED_ORIGINS'],
    'cors null origin' => [['CORS_ALLOWED_ORIGINS' => [ProductionConfig::WEB, 'null']], 'CORS_ALLOWED_ORIGINS'],
    'cors http origin' => [['CORS_ALLOWED_ORIGINS' => [ProductionConfig::WEB, 'http://other.example']], 'CORS_ALLOWED_ORIGINS'],
    'cors without the spa origin' => [['CORS_ALLOWED_ORIGINS' => ['https://other.example']], 'CORS_ALLOWED_ORIGINS'],
    'cors empty' => [['CORS_ALLOWED_ORIGINS' => []], 'CORS_ALLOWED_ORIGINS'],
    'cors with a path' => [['CORS_ALLOWED_ORIGINS' => ['https://portal.example/app']], 'CORS_ALLOWED_ORIGINS'],
    'stateful star' => [['SANCTUM_STATEFUL_DOMAINS' => ['*']], 'SANCTUM_STATEFUL_DOMAINS'],
    'stateful wildcard' => [['SANCTUM_STATEFUL_DOMAINS' => ['portal.example', '*.example']], 'SANCTUM_STATEFUL_DOMAINS'],
    'stateful other host' => [['SANCTUM_STATEFUL_DOMAINS' => ['other.example']], 'SANCTUM_STATEFUL_DOMAINS'],
    'stateful empty entry' => [['SANCTUM_STATEFUL_DOMAINS' => ['portal.example', '']], 'SANCTUM_STATEFUL_DOMAINS'],
    'no trusted hosts' => [['TRUSTED_HOSTS' => []], 'TRUSTED_HOSTS'],
    'debug log level' => [['LOG_LEVEL' => 'debug'], 'LOG_LEVEL'],
    'debug log level any case' => [['LOG_LEVEL' => 'DEBUG'], 'LOG_LEVEL'],
    'log mailer' => [['MAIL_MAILER' => 'log'], 'MAIL_MAILER'],
    'array mailer' => [['MAIL_MAILER' => 'array'], 'MAIL_MAILER'],
]);

it('refuses an empty TRUSTED_PROXIES unless TRUSTED_PROXIES_NONE=true says clients reach the app directly', function (array $override, array $errors) {
    expect(ProductionGuard::check(guardSettings($override)))->toBe(['errors' => $errors, 'warnings' => []]);
})->with([
    'empty, no acknowledgement' => [['TRUSTED_PROXIES' => []], ['TRUSTED_PROXIES']],
    'empty, acknowledgement null' => [['TRUSTED_PROXIES' => [], 'TRUSTED_PROXIES_NONE' => null], ['TRUSTED_PROXIES']],
    'empty, acknowledgement false' => [['TRUSTED_PROXIES' => [], 'TRUSTED_PROXIES_NONE' => false], ['TRUSTED_PROXIES']],
    'empty, acknowledgement a string' => [['TRUSTED_PROXIES' => [], 'TRUSTED_PROXIES_NONE' => 'true'], ['TRUSTED_PROXIES']],
    'empty, acknowledged' => [['TRUSTED_PROXIES' => [], 'TRUSTED_PROXIES_NONE' => true], []],
    'listed' => [['TRUSTED_PROXIES' => ['10.0.0.0/8']], []],
]);

it('collects several errors in one run', function () {
    $result = ProductionGuard::check(guardSettings(['APP_DEBUG' => true, 'SESSION_SECURE_COOKIE' => false, 'TRUSTED_HOSTS' => []]));

    expect($result['errors'])->toBe(['APP_DEBUG', 'SESSION_SECURE_COOKIE', 'TRUSTED_HOSTS']);
});

it('only warns about a file cache, the sync queue and a placeholder invoicing seller', function (array $override, string $rule) {
    expect(ProductionGuard::check(guardSettings($override)))->toBe(['errors' => [], 'warnings' => [$rule]]);
})->with([
    'file cache' => [['CACHE_STORE' => 'file'], 'CACHE_STORE'],
    'sync queue' => [['QUEUE_CONNECTION' => 'sync'], 'QUEUE_CONNECTION'],
    'placeholder seller' => [['INVOICING_SELLER_NAME' => 'PLACEHOLDER SELLER NAME'], 'INVOICING_SELLER_NAME'],
    'placeholder seller any case' => [['INVOICING_SELLER_NAME' => 'My placeholder Ltd'], 'INVOICING_SELLER_NAME'],
]);

it('accepts the web origin with a port in the stateful list', function () {
    $s = guardSettings(['APP_URL_WEB' => 'https://portal.example:8443', 'CORS_ALLOWED_ORIGINS' => ['https://portal.example:8443'], 'SANCTUM_STATEFUL_DOMAINS' => ['portal.example:8443']]);

    expect(ProductionGuard::check($s)['errors'])->toBe([]);
});

it('never puts a configuration VALUE into the result', function () {
    $secret = 'https://hunter2-secret.example';
    $result = ProductionGuard::check(guardSettings(['APP_URL' => 'http://hunter2-secret.example', 'APP_URL_WEB' => $secret.'/x?token=abc', 'CORS_ALLOWED_ORIGINS' => ['http://hunter2-secret.example'], 'LOG_LEVEL' => 'debug']));

    expect(json_encode($result))->not->toContain('hunter2')->not->toContain('token=abc');
});

it('applies to every environment except local, testing and loadtest (trimmed, case-insensitive)', function (string $env, bool $applies) {
    app()->detectEnvironment(fn () => $env);

    expect(ProductionGuard::applies())->toBe($applies);
})->with([
    ['production', true], ['staging', true], ['prod', true], ['live', true], ['Production', true], ['PRODUCTION', true], ['', true], ['   ', true],
    ['development', true], ['qa', true], ['local-ish', true], ['testing2', true],
    ['local', false], ['testing', false], ['loadtest', false], ['LOCAL', false], ['Testing', false], [' loadtest ', false],
]);

it('reads the live configuration', function () {
    expect(array_keys(ProductionGuard::settings()))->toBe(array_keys(ProductionConfig::safeSettings()));
});
