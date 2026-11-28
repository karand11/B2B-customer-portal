<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * The framework skips CSRF validation while unit tests run (VerifyCsrfToken::runningUnitTests()), so no ordinary test can see a 419.
 * CsrfEnforced::enable() rebinds the middleware (Sanctum resolves it from the container) to a subclass that does not skip, for the current test only
 * (the application is rebuilt for every test). The token is then checked exactly as in production: header X-XSRF-TOKEN (decrypted cookie value) or X-CSRF-TOKEN.
 */
class CsrfEnforced extends ValidateCsrfToken
{
    public static function enable(): void
    {
        app()->bind(ValidateCsrfToken::class, fn ($app) => new self($app, $app['encrypter']));
    }

    protected function runningUnitTests()
    {
        return false;
    }
}
