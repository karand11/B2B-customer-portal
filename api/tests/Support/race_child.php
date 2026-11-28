<?php

/**
 * One contender of a race test, run as a separate PHP process (see tests/Feature/Admin/ConcurrencyTest.php). It boots the app on the test database,
 * does one action and prints {"status": <http status>}. Argument 1 is JSON: {"kind": "assign"|"accept"|"reset", ...}.
 */

use App\Models\User;
use App\Services\UserAdmin;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$database = (string) config('database.connections.pgsql.database');
if (preg_match('/_test(_\d+)?$/D', $database) !== 1) {
    fwrite(STDERR, "refusing to run against [$database]\n");
    exit(2);
}

$job = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);

$post = function (string $uri, array $body) use ($app): int {
    $request = Request::create($uri, 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:5173',
    ], json_encode($body));

    return $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request)->getStatusCode();
};

try {
    $status = match ($job['kind']) {
        'assign' => (function () use ($job) {
            app(UserAdmin::class)->assign(User::findOrFail($job['actor']), User::findOrFail($job['target']), $job['role'], null);

            return 200;
        })(),
        'accept' => $post('/api/v1/auth/accept-invitation', $job['body']),
        'reset' => $post('/api/v1/auth/reset-password', $job['body']),
    };
} catch (ValidationException) {
    $status = 422;
}

echo json_encode(['status' => $status]);
