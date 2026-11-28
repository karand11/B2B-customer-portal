<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MatrixWorld;
use Tests\Support\RouteMatrix;

/** Keys a client must never be able to set through any write route. The values are sentinels: easy to find if one is ever stored. */
function secForgedKeys(object $w): array
{
    return [
        'role' => 'admin', 'company_id' => $w->b->id, 'active' => false, 'session_epoch' => 7770777, 'password' => 'Hacked-Passw0rd-1', 'email_verified_at' => '2000-01-01 00:00:00',
        'invited_by_user_id' => $w->admin->id, 'version' => 9990999, 'status' => 'delivered', 'price_cents' => 1, 'unit_price_cents' => 1, 'subtotal_cents' => 1,
        'total_cents' => 1, 'number' => 'FORGED-NUM', 'created_by_user_id' => $w->admin->id, 'user_id' => $w->admin->id, 'id' => 987654321, 'public_id' => 'forged-public-id',
    ];
}

/**
 * Forged keys a route legitimately reads (a documented input, not a leak): they are the route's own parameters.
 * Everything else of the forged list stays in the request. A key already in the valid body is the route's own value and is not forged on top.
 */
const SEC_MASS_EXCEPTIONS = [
    'POST api/v1/admin/products' => ['active'], // optional on creation (contract)
];

// Ids, random numbers and hashes, plus what Faker invents for users the request itself creates (email, name) and random storage names.
const SEC_VOLATILE_COLUMNS = ['id', 'public_id', 'order_number', 'quote_number', 'token', 'remember_token', 'password', 'order_id', 'quote_request_id', 'invoice_id', 'import_id', 'email', 'name', 'storage_path', 'model_id'];

/** Every table a write route can touch, as a sorted list of rows without the columns that legitimately differ between two runs (ids, random numbers, hashes). */
function secStateSnapshot(): array
{
    $state = [];
    foreach ([
        'users', 'model_has_roles', 'companies', 'products', 'orders', 'order_items', 'order_status_history', 'cart_items', 'quote_requests', 'quote_items', 'quote_status_history',
        'quote_attachments', 'invoices', 'invoice_items', 'invoice_status_history', 'product_imports', 'user_invitation_tokens', 'audit_log',
    ] as $table) {
        $rows = DB::table($table)->get()->map(function ($row) use ($table) {
            $row = (array) $row;
            // Audit rows name the random numbers and ids of the rows they describe; their action, actor, company and changes are compared.
            $table === 'audit_log' && $row = array_diff_key($row, array_flip(['subject_id', 'subject_label', 'metadata']) + ($row['action'] === 'company.created' ? ['company_id' => 1] : []) + ($row['action'] === 'user.invitation_accepted' ? ['actor_user_id' => 1] : []));
            foreach (SEC_VOLATILE_COLUMNS as $column) {
                unset($row[$column]);
            }
            ksort($row);

            return json_encode($row);
        })->all();
        sort($rows);
        $state[$table] = $rows;
    }

    return $state;
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-25 12:00:00', 'UTC'));
    Mail::fake();
    $this->w = MatrixWorld::build();
});

afterEach(fn () => $this->travelBack());

/** @return array<string, array{string}> every write route of the router */
function secWriteRoutes(): array
{
    $rows = [];
    foreach (array_keys(RouteMatrix::ROUTES) as $key) {
        str_starts_with($key, 'GET ') || $rows[$key] = [$key];
    }

    return $rows;
}

/** Run the route once inside a savepoint (rolled back), returning [status, state after]. */
function secMassRun(object $t, string $key, array $forged): array
{
    DB::beginTransaction();
    try {
        Cache::flush();
        [$actor, $url, $body] = MatrixWorld::spec($key, $t->w);
        $method = explode(' ', $key, 2)[0];
        $payload = $body + array_diff_key($forged, $body, array_flip(SEC_MASS_EXCEPTIONS[$key] ?? []));

        $actor === null ? app('auth')->forgetGuards() : $t->actingAs($actor);
        $hasFile = (bool) array_filter($payload, fn ($v) => $v instanceof UploadedFile);
        $response = $hasFile ? $t->call($method, $url, array_filter($payload, fn ($v) => ! $v instanceof UploadedFile), [], array_filter($payload, fn ($v) => $v instanceof UploadedFile), ['HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:5173'])
            : $t->json($method, $url, $payload);

        return [$response->getStatusCode(), secStateSnapshot(), $response->getContent()];
    } finally {
        DB::rollBack();
    }
}

it('ignores forged role, company, price, status, number and id keys on every write route: the same status and the same rows as the clean request', function (string $key) {
    [$controlStatus, $controlState] = secMassRun($this, $key, []);
    [$forgedStatus, $forgedState, $content] = secMassRun($this, $key, secForgedKeys($this->w));

    expect($controlStatus)->toBeLessThan(300, "{$key}: the valid request must succeed, or a 422 could hide a leak");
    expect($forgedStatus)->toBe($controlStatus, "{$key}: forged keys changed the status ({$content})");

    foreach ($controlState as $table => $rows) {
        expect($forgedState[$table])->toBe($rows, "{$key}: forged keys changed rows of [{$table}]");
    }

    $everything = json_encode($forgedState);
    foreach (['FORGED-NUM', 'forged-public-id', '7770777', '9990999', '987654321', 'Hacked'] as $sentinel) {
        expect(str_contains($everything, $sentinel))->toBeFalse("{$key}: the sentinel [{$sentinel}] was stored");
    }
})->with(fn () => secWriteRoutes());

it('never lets any user, forged or not, end up with the forged password', function () {
    // The sweep above hashes nothing it does not need; this is the explicit guard for the one forged secret.
    DB::beginTransaction();
    foreach (['POST api/v1/auth/login', 'POST api/v1/auth/forgot-password', 'POST api/v1/admin/users'] as $key) {
        [$actor, $url, $body] = MatrixWorld::spec($key, $this->w);
        $actor === null ? $this->app['auth']->forgetGuards() : $this->actingAs($actor);
        $this->postJson($url, $body + array_diff_key(secForgedKeys($this->w), $body));
        Cache::flush();
    }
    foreach (User::all() as $user) {
        expect(Hash::check('Hacked-Passw0rd-1', $user->password))->toBeFalse();
    }
    DB::rollBack();
});

it('documents every exception: a legitimately read key is part of the route\'s own input', function () {
    foreach (SEC_MASS_EXCEPTIONS as $key => $keys) {
        expect(RouteMatrix::ROUTES)->toHaveKey($key);
        expect($keys)->not->toContain('role')->not->toContain('company_id')->not->toContain('price_cents');
    }
});
