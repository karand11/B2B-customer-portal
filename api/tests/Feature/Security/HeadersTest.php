<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\QuoteAttachment;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\CsrfEnforced;
use Tests\Support\ProductionConfig;
use Tests\Support\RouteMatrix;

afterEach(fn () => Request::setTrustedHosts([]));

/** The header set every Laravel response must carry (docs/api-contract.md, Phase 7 "Response headers"). */
function secAssertHeaders(TestResponse $response, string $context, bool $api = true): TestResponse
{
    $h = $response->headers;

    expect($h->get('X-Content-Type-Options'))->toBe('nosniff', $context)
        ->and($h->get('Referrer-Policy'))->toBe('origin', $context)
        ->and($h->get('X-Frame-Options'))->toBe('DENY', $context)
        ->and($h->get('Permissions-Policy'))->toBe(SecurityHeaders::PERMISSIONS_POLICY, $context)
        ->and($h->get('Cross-Origin-Resource-Policy'))->toBe('same-site', $context)
        ->and($h->get('Content-Security-Policy'))->not->toBeNull($context)
        ->and($h->get('X-Request-Id'))->not->toBeNull($context)
        ->and($h->has('X-Powered-By'))->toBeFalse($context)
        ->and($h->has('X-XSS-Protection'))->toBeFalse($context);
    $api && expect(str_contains((string) $h->get('Cache-Control'), 'no-store'))->toBeTrue("{$context}: Cache-Control");

    return $response;
}

beforeEach(function () {
    Route::get('/api/v1/_sec_boom', fn () => throw new RuntimeException('secret internal detail'));
    Route::post('/api/v1/_sec_big', fn () => throw new PostTooLargeException);
});

it('sends the exact header set on every api route, for a guest and for a signed-in user', function () {
    $user = makeUser('admin');

    foreach (RouteMatrix::routes() as $key => [$method, $uri]) {
        foreach ([null, $user] as $caller) {
            $caller === null ? $this->app['auth']->forgetGuards() : $this->actingAs($caller);
            $response = $this->json($method, RouteMatrix::url($uri), []);

            secAssertHeaders($response, "{$key} as ".($caller ? 'admin' : 'guest').' -> '.$response->getStatusCode());
        }
        Cache::flush();
    }
});

it('sends the header set on /up and the CSRF cookie route (no-store only below api and sanctum)', function () {
    secAssertHeaders($this->get('/up'), '/up', api: false);
    $csrf = secAssertHeaders($this->get('/sanctum/csrf-cookie'), 'csrf-cookie');

    expect($csrf->getStatusCode())->toBe(204);
});

it('keeps the headers on every error status', function (string $name, Closure $request, int $status) {
    $response = $request($this);

    expect($response->getStatusCode())->toBe($status);
    secAssertHeaders($response, "{$name} ({$status})");
})->with([
    '401' => ['401', fn ($t) => $t->getJson('/api/v1/me'), 401],
    '404' => ['404', fn ($t) => $t->getJson('/api/v1/does-not-exist'), 404],
    '405' => ['405', fn ($t) => $t->postJson('/api/v1/health'), 405],
    '413' => ['413', fn ($t) => $t->postJson('/api/v1/_sec_big'), 413],
    '419' => ['419', function ($t) {
        CsrfEnforced::enable();

        return $t->postJson('/api/v1/auth/forgot-password', ['email' => 'a@b.test']);
    }, 419],
    '422' => ['422', fn ($t) => $t->postJson('/api/v1/auth/forgot-password', []), 422],
    '500' => ['500', fn ($t) => $t->getJson('/api/v1/_sec_boom'), 500],
]);

it('keeps the headers on a 429', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'a@b.test']);
    }

    $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'a@b.test']);
    expect($response->getStatusCode())->toBe(429);
    secAssertHeaders($response, '429');
});

it('keeps the headers on a 500 and does not leak the exception', function () {
    $response = $this->getJson('/api/v1/_sec_boom');

    expect($response->getStatusCode())->toBe(500)->and($response->getContent())->not->toContain('secret internal detail');
});

it('keeps the headers on a CORS preflight, allowed or not', function (string $origin) {
    $response = $this->withHeaders(['Origin' => $origin, 'Access-Control-Request-Method' => 'POST'])->options('/api/v1/auth/login');

    secAssertHeaders($response, "preflight from {$origin}");
})->with(['http://localhost:5173', 'http://evil.test']);

it('sends exactly Referrer-Policy origin, never no-referrer', function () {
    expect($this->getJson('/api/v1/health')->headers->get('Referrer-Policy'))->toBe('origin');
});

it('returns a fresh request id per response and honours a safe inbound one only', function (?string $inbound, bool $kept) {
    $response = $this->getJson('/api/v1/health', $inbound === null ? [] : ['X-Request-Id' => $inbound]);
    $id = $response->headers->get('X-Request-Id');

    expect($id)->not->toBeNull();
    $kept ? expect($id)->toBe($inbound) : expect($id)->toMatch(UUID_V4);
})->with([
    'none' => [null, false],
    'safe' => ['req-1234.abcd_EF', true],
    'min length' => ['abcdefgh', true],
    'max length' => [str_repeat('a', 64), true],
    'too short' => ['abc', false],
    'too long' => [str_repeat('a', 65), false],
    'space' => ['abcd efgh', false],
    'newline injection' => ["abcdefgh\r\nSet-Cookie: x=1", false],
    'html' => ['<script>alert(1)</script>', false],
    'slash' => ['abcd/efgh1', false],
]);

it('lets the browser read X-Request-Id and Retry-After (CORS expose list)', function () {
    $response = $this->withHeaders(['Origin' => 'http://localhost:5173'])->getJson('/api/v1/health');

    expect($response->headers->get('Access-Control-Expose-Headers'))->toContain('X-Request-Id')->toContain('Retry-After');
});

it('does not send HSTS over plain HTTP, in local, in testing or when disabled', function (string $env, string $url, ?int $maxAge) {
    ProductionConfig::apply($env);
    $maxAge === null || config(['portal.hsts_max_age' => $maxAge]);

    expect($this->getJson($url)->headers->has('Strict-Transport-Security'))->toBeFalse();
})->with([
    'production over http' => ['production', 'http://'.ProductionConfig::HOST.'/api/v1/health', null],
    'staging over http' => ['staging', 'http://'.ProductionConfig::HOST.'/api/v1/health', null],
    'local over https' => ['local', 'https://'.ProductionConfig::HOST.'/api/v1/health', null],
    'testing over https' => ['testing', 'https://'.ProductionConfig::HOST.'/api/v1/health', null],
    'loadtest over https' => ['loadtest', 'https://'.ProductionConfig::HOST.'/api/v1/health', null],
    'LOCAL over https' => ['LOCAL', 'https://'.ProductionConfig::HOST.'/api/v1/health', null],
    'production https but disabled' => ['production', 'https://'.ProductionConfig::HOST.'/api/v1/health', 0],
]);

it('sends HSTS with max-age only (no includeSubDomains, no preload) on a secure request in every environment except local, testing and loadtest', function (string $env) {
    ProductionConfig::apply($env);

    $response = $this->getJson('https://'.ProductionConfig::HOST.'/api/v1/health');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000');
})->with(['production', 'staging', 'prod', 'live', 'Production', 'PRODUCTION']);

it('decides "secure" after trusted-proxy resolution: X-Forwarded-Proto counts only from a listed proxy', function (string $peer, bool $hsts) {
    ProductionConfig::apply();

    $response = $this->withServerVariables(['REMOTE_ADDR' => $peer])->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->getJson('http://'.ProductionConfig::HOST.'/api/v1/health');

    expect($response->getStatusCode())->toBe(200)->and($response->headers->has('Strict-Transport-Security'))->toBe($hsts);
})->with([['10.1.2.3', true], ['192.0.2.9', false]]);

it('keeps the own Content-Security-Policy and private cache headers of attachment and PDF downloads', function () {
    Storage::fake('quotes');
    $w = quoteWorld();
    $quote = makeQuote($w, 'quoted');
    $att = QuoteAttachment::factory()->create(['quote_request_id' => $quote->id]);

    $file = $this->actingAs($w->a1)->get("/api/v1/quotes/{$quote->quote_number}/attachments/{$att->public_id}");
    expect($file->getStatusCode())->toBe(200)
        ->and($file->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
        ->and($file->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    secAssertHeaders($file, 'attachment download');

    $invoice = makeInvoice($w->a);
    $pdf = $this->actingAs($w->a1)->get("/api/v1/invoices/{$invoice->public_id}/pdf");
    expect($pdf->getStatusCode())->toBe(200)->and($pdf->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($pdf->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox");
    secAssertHeaders($pdf, 'invoice pdf');
});

it('removes X-Powered-By set by the response itself', function () {
    Route::get('/api/v1/_sec_powered', fn () => response('x')->header('X-Powered-By', 'PHP/8.3')->header('X-XSS-Protection', '1; mode=block'));

    $response = $this->get('/api/v1/_sec_powered');

    expect($response->headers->has('X-Powered-By'))->toBeFalse()->and($response->headers->has('X-XSS-Protection'))->toBeFalse();
});
