<?php

use App\Http\Middleware\EnforceProductionConfig;
use App\Http\Middleware\RequestContext;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustConfiguredHosts;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Support\CsvCell;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;

/** @return array<string, string> path relative to api/ => contents, for every PHP file under the given directories */
function secSourceFiles(array $dirs, bool $blade = false): array
{
    $files = [];
    foreach ($dirs as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)) as $file) {
            $isCode = $file->getExtension() === 'php';
            if ($isCode || ($blade && str_ends_with($file->getFilename(), '.blade.php'))) {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1))] = file_get_contents($file->getPathname());
            }
        }
    }

    return $files;
}

it('never writes CSV without the CsvCell guard (no export exists today; a future one must use the helper)', function () {
    foreach (secSourceFiles(['app']) as $path => $code) {
        if (str_contains($code, 'fputcsv') || str_contains($code, 'SplFileObject') || preg_match('/->putCsv|League\\\\Csv|PhpSpreadsheet/', $code)) {
            expect(str_contains($code, 'CsvCell'))->toBeTrue("{$path} writes CSV or a spreadsheet without CsvCell::safe()");
        }
    }
    expect(class_exists(CsvCell::class))->toBeTrue();
});

it('never assigns the AuditLog transaction-depth seam in application code', function () {
    foreach (secSourceFiles(['app', 'routes', 'bootstrap', 'database']) as $path => $code) {
        expect(preg_match('/(AuditLog|self|static)::\$baseTransactionLevel\s*=[^=]/', $code))->toBe(0, "{$path} assigns the seam");
    }
});

it('keeps remote resources and PHP in PDFs switched off', function () {
    expect(config('dompdf.options.enable_remote') ?? config('dompdf.enable_remote'))->toBeFalse()
        ->and(config('dompdf.options.enable_php') ?? config('dompdf.enable_php'))->toBeFalse();
    $text = file_get_contents(config_path('dompdf.php'));
    expect($text)->toMatch("/'enable_remote'\s*=>\s*false/")->toMatch("/'enable_php'\s*=>\s*false/");
});

it('reads the request through validated data in form requests, never ->get() on $this->request', function () {
    foreach (secSourceFiles(['app/Http/Requests']) as $path => $code) {
        expect(preg_match('/\$this->request->get\(/', $code))->toBe(0, $path);
    }
});

it('calls env() in config files only (it returns null once the configuration is cached)', function () {
    foreach (secSourceFiles(['app', 'routes', 'database', 'resources'], blade: true) as $path => $code) {
        expect(preg_match('/(?<![\w>:])env\(/', $code))->toBe(0, "{$path} calls env()");
    }
});

it('never passes the whole request to create, update, fill or forceFill', function () {
    foreach (secSourceFiles(['app']) as $path => $code) {
        expect(preg_match('/(create|update|fill|forceFill|insert|upsert)\(\s*(request\(\)|\$request|\$this)->(all|input)\(\s*\)/', $code))->toBe(0, $path);
        expect(preg_match('/(create|update|fill|forceFill)\(\s*\$request->validated\(\)\s*\)/', $code))->toBe(0, "{$path}: mass assignment of validated input (build the columns explicitly)");
    }
});

it('serves no file from storage: no public disk in the upload code, no serve flag, no storage link, no storage route', function () {
    foreach (['local', 'quotes', 'imports'] as $disk) {
        expect(config("filesystems.disks.{$disk}.serve"))->toBeFalse($disk)
            ->and(config("filesystems.disks.{$disk}.url"))->toBeNull($disk)
            ->and(config("filesystems.disks.{$disk}.visibility") ?? 'private')->toBe('private', $disk);
    }
    expect(config('quotes.attachments.disk'))->toBe('quotes')->and(config('admin.imports.disk'))->toBe('imports');

    foreach (secSourceFiles(['app']) as $path => $code) {
        expect(preg_match("/Storage::disk\\(['\"]public['\"]\\)|Storage::(url|temporaryUrl)\\(|->(url|temporaryUrl)\\(\\s*\\\$[a-z]*(path|file)/i", $code))->toBe(0, "{$path} may expose a stored file by URL");
    }

    expect(file_exists(public_path('storage')) || is_link(public_path('storage')))->toBeFalse()
        ->and(collect(app('router')->getRoutes()->getRoutes())->contains(fn ($r) => str_starts_with($r->uri(), 'storage')))->toBeFalse();
});

it('never logs an exception message, only its class (messages can contain addresses and SQL values)', function () {
    foreach (secSourceFiles(['app']) as $path => $code) {
        // bootstrap/app.php is checked separately: it logs the SQLSTATE and the placeholder SQL.
        expect(preg_match('/Log::\w+\([^;]*getMessage\(\)/s', $code))->toBe(0, "{$path} logs an exception message");
    }
});

it('has no welcome page and no web route on the API host', function () {
    expect(file_exists(resource_path('views/welcome.blade.php')))->toBeFalse();
    $web = file_get_contents(base_path('routes/web.php'));

    expect(preg_match('/Route::(get|post|any|view)\(/', $web))->toBe(0);
});

it('registers the Phase 7 global middleware outermost, in the right order', function () {
    $global = app(Kernel::class)->getGlobalMiddleware();

    expect(array_slice($global, 0, 3))->toBe([
        SecurityHeaders::class, RequestContext::class, EnforceProductionConfig::class,
    ]);
    expect($global)->toContain(TrustConfiguredProxies::class)->toContain(TrustConfiguredHosts::class)
        ->not->toContain(TrustProxies::class)->not->toContain(TrustHosts::class);
    // SecurityHeaders wraps CORS, so a preflight carries the headers too.
    expect(array_search(SecurityHeaders::class, $global))->toBeLessThan(array_search(HandleCors::class, $global));
});

it('keeps composer.json free of new Phase 7 dependencies', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);

    expect(array_keys($composer['require']))->toBe(['php', 'barryvdh/laravel-dompdf', 'laravel/framework', 'laravel/sanctum', 'laravel/tinker', 'spatie/laravel-permission']);
});
