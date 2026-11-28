<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MatrixWorld;
use Tests\Support\RouteMatrix;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-25 12:00:00', 'UTC'));
    Mail::fake();
    $this->w = MatrixWorld::build();
    // Something recognisable to look for in a response that must NOT contain it.
    $this->w->A->order->forceFill(['po_reference' => 'SECRET-PO-OF-A'])->save();
    $this->w->A->att->forceFill(['original_name' => 'a-secret-drawing.pdf'])->save();
});

afterEach(fn () => $this->travelBack());

/** The company-scoped routes that carry an identifier: [key] => [key]. */
function secScopedRoutes(string $kind = 'company:'): array
{
    $keys = [];
    foreach (RouteMatrix::SCOPE as $key => $scope) {
        str_starts_with($scope, $kind) && $keys[$key] = [$key];
    }

    return $keys;
}

function secHasId(string $key): bool
{
    return (bool) preg_match('/\{(number|invoice|attachment)\}/', $key);
}

/** The URL (and body) of a route, aimed at the rows of company set A or B. */
function secMatrixTarget(string $key, object $w, string $set = 'A'): array
{
    [$method, $uri] = explode(' ', $key, 2);

    if ($method !== 'GET') {
        [, $url, $body] = MatrixWorld::spec($key, $w, $set);

        return [$method, $url, $body];
    }

    $s = $w->{$set};
    $scope = RouteMatrix::SCOPE[$key];
    $url = '/'.preg_replace_callback('/\{(\w+)\}/', fn ($m) => match ($m[1]) {
        'number' => $scope === 'company:order' ? $s->order->order_number : ($scope === 'company:attachment' ? $s->submitted->quote_number : $s->quoted->quote_number),
        'invoice' => $s->invoice->public_id,
        'attachment' => $s->att->public_id,
    }, $uri);

    return [$method, $url, []];
}

/** The same URL with every identifier of the world replaced by an unknown one of the same shape. */
function secUnknownTarget(string $url, object $w): string
{
    $map = [];
    foreach (['A', 'B'] as $label) {
        $s = $w->{$label};
        foreach ([$s->order, $s->pendingOrder] as $order) {
            $map[$order->order_number] = 'ORD-AAAAA-AAAAA';
        }
        foreach ([$s->submitted, $s->review, $s->quoted] as $quote) {
            $map[$quote->quote_number] = 'QUO-AAAAA-AAAAA';
        }
        foreach ([$s->invoice->public_id, $s->att->public_id, $s->staffAtt->public_id] as $uuid) {
            $map[$uuid] = '00000000-0000-4000-8000-000000000000';
        }
    }

    return strtr($url, $map);
}

/** Everything cross-company requests could change in A, as one comparable string. */
function secCompanySnapshot(object $w): string
{
    $rows = [];
    foreach (['orders', 'order_items', 'order_status_history', 'quote_requests', 'quote_items', 'quote_status_history', 'quote_attachments', 'invoices', 'invoice_items', 'invoice_status_history', 'cart_items', 'audit_log', 'users', 'companies'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => json_encode($r))->all();
    }
    $rows['files'] = Storage::disk('quotes')->allFiles();

    return json_encode($rows);
}

function secAccess(string $key): string
{
    return RouteMatrix::ROUTES[$key];
}

it('classifies every route: the scope table equals the router and every route with an id is company scoped', function () {
    $router = array_keys(RouteMatrix::routes());

    expect(array_values(array_diff($router, array_keys(RouteMatrix::SCOPE))))->toBe([], 'Routes missing from RouteMatrix::SCOPE: decide whose data they touch.')
        ->and(array_values(array_diff(array_keys(RouteMatrix::SCOPE), $router)))->toBe([], 'SCOPE entries without a route.');

    foreach (RouteMatrix::SCOPE as $key => $scope) {
        expect($scope)->toBeIn(['public', 'shared', 'user', 'company:order', 'company:quote', 'company:invoice', 'company:attachment', 'admin'], $key);
        if (secHasId($key)) {
            expect($scope)->toStartWith('company:', "{$key} has an order, quote, invoice or attachment id but is not company scoped");
        }
        // The back office is staff or admin only.
        $scope === 'admin' && expect(secAccess($key))->toBeIn(['staff', 'admin'], $key);
    }
});

it('has a valid request for every write route (so a 422 can never hide a leak), all of which the matrix can build', function () {
    foreach (RouteMatrix::ROUTES as $key => $access) {
        if (str_starts_with($key, 'GET ')) {
            continue;
        }
        DB::beginTransaction();
        [$actor, $url] = MatrixWorld::spec($key, $this->w);
        DB::rollBack();

        expect($url)->toStartWith('/api/v1/');
        $actor === null ? expect($access)->toBe('public') : expect($actor)->toBeInstanceOf(User::class);
    }
});

it('answers a customer of another company with the exact 404 on every company route, identical to an unknown id, and changes nothing', function (string $key) {
    [$method, $url, $body] = secMatrixTarget($key, $this->w);
    $unknown = secUnknownTarget($url, $this->w);
    $before = secCompanySnapshot($this->w);
    $this->actingAs($this->w->b1);

    $foreign = $this->json($method, $url, $body);
    $missing = $this->json($method, $unknown, $body);

    if (in_array(secAccess($key), ['auth', 'customer'], true)) {
        expect($foreign->getStatusCode())->toBe(404, $key)->and($foreign->getContent())->toBe('{"message":"Not found."}')
            ->and($missing->getStatusCode())->toBe(404)->and($missing->getContent())->toBe($foreign->getContent())
            ->and(str_contains($foreign->getContent(), '%PDF'))->toBeFalse();
    } else {
        // Staff routes: a customer is stopped by the role, before any lookup, for existing and unknown ids alike.
        expect($foreign->getStatusCode())->toBe(403, $key)->and($missing->getStatusCode())->toBe(403)->and($missing->getContent())->toBe($foreign->getContent());
    }

    expect(secCompanySnapshot($this->w))->toBe($before);
})->with(fn () => array_filter(secScopedRoutes(), fn ($row) => secHasId($row[0])));

it('lets a colleague of the same company read what the route shows', function (string $key) {
    [, $url] = secMatrixTarget($key, $this->w);
    $this->actingAs($this->w->a2);

    $response = $this->getJson($url);

    expect($response->getStatusCode())->toBe(200, $key);
})->with(fn () => array_filter(secScopedRoutes(), fn ($row) => secHasId($row[0]) && str_starts_with($row[0], 'GET ')));

it('answers a customer without a company with 404 for every id and an empty list for every list', function (string $key) {
    $this->actingAs($this->w->loner);

    if (secHasId($key)) {
        [$method, $url, $body] = secMatrixTarget($key, $this->w);
        $response = $this->json($method, $url, $body);
        expect($response->getStatusCode())->toBeIn([404, 403], $key);
        in_array(secAccess($key), ['auth', 'customer'], true) && expect($response->getStatusCode())->toBe(404, $key);
    } else {
        $response = $this->getJson('/'.explode(' ', $key, 2)[1]);
        expect($response->getStatusCode())->toBe(200, $key)->and($response->json('data'))->toBe([])->and($response->json('meta.total'))->toBe(0);
    }
})->with(fn () => array_filter(secScopedRoutes(), fn ($row) => in_array(secAccess($row[0]), ['auth', 'customer'], true) && (secHasId($row[0]) || str_starts_with($row[0], 'GET '))));

it('never shows staff or admin a 404 for an existing id on a read route', function (string $key, string $role) {
    [, $url] = secMatrixTarget($key, $this->w);
    $this->actingAs($role === 'staff' ? $this->w->staff : $this->w->admin);

    expect($this->getJson($url)->getStatusCode())->toBe(200, $key);
})->with(fn () => array_filter(secScopedRoutes(), fn ($row) => secHasId($row[0]) && str_starts_with($row[0], 'GET ')))->with(['staff', 'admin']);

it('answers a guest with 401 on every route that needs a user, whatever the id', function (string $key) {
    [$method, $url, $body] = secMatrixTarget($key, $this->w);
    $this->app['auth']->forgetGuards();

    $response = $this->json($method, $url, $body);

    expect($response->getStatusCode())->toBe(secAccess($key) === 'public' ? $response->getStatusCode() : 401, $key);
    secAccess($key) === 'public' || expect($response->getStatusCode())->toBe(401, $key);
})->with(fn () => secScopedRoutes());

it('shows another company nothing of A in any list, whatever the filters, search or page', function (string $uri) {
    $w = $this->w;
    $a = $w->A;
    $needles = [
        $a->order->order_number, $a->pendingOrder->order_number, $a->submitted->quote_number, $a->review->quote_number, $a->quoted->quote_number,
        $a->invoice->public_id, $a->invoice->number, $a->att->public_id, $a->staffAtt->public_id, 'SECRET-PO-OF-A', 'a-secret-drawing.pdf',
    ];
    $queries = [
        '', '?per_page=100&page=1', "?company_id={$w->a->id}", '?q=SECRET-PO-OF-A', "?q={$a->order->order_number}", "?q={$a->quoted->quote_number}", "?q={$a->invoice->number}",
        '?status=pending,confirmed,submitted,in_review,quoted,open,paid,overdue,void&per_page=100', '?sort=-created_at&per_page=100', "?company_id={$w->a->id}&q=SECRET&status=confirmed&per_page=100",
    ];
    $this->actingAs($w->b1);

    foreach ($queries as $query) {
        $response = $this->getJson($uri.$query);
        expect($response->getStatusCode())->toBeIn([200, 422], "{$uri}{$query}");
        foreach ($needles as $needle) {
            expect(str_contains($response->getContent(), $needle))->toBeFalse("{$uri}{$query} leaked [{$needle}]");
        }
    }

    // ...while A itself sees them.
    $this->actingAs($w->a1);
    expect($this->getJson($uri.'?per_page=100')->json('meta.total'))->toBeGreaterThan(0);
})->with(['/api/v1/orders', '/api/v1/quotes', '/api/v1/invoices']);

it('sends a company filter from a customer nowhere: it is ignored, not an error and not a way into another company', function () {
    $this->actingAs($this->w->b1);

    foreach (['orders', 'quotes', 'invoices'] as $list) {
        $response = $this->getJson("/api/v1/{$list}?company_id={$this->w->a->id}&per_page=100")->assertOk();

        foreach ($response->json('data') as $row) {
            expect($row['company']['id'])->toBe($this->w->b->id);
        }
    }
});

it('does not hand out a PDF or a file of another company, not even the first bytes', function () {
    $this->actingAs($this->w->b1);

    $pdf = $this->get("/api/v1/invoices/{$this->w->A->invoice->public_id}/pdf");
    $file = $this->get("/api/v1/quotes/{$this->w->A->submitted->quote_number}/attachments/{$this->w->A->att->public_id}");
    // ...and the file of A's quote requested through B's own quote number:
    $mixed = $this->get("/api/v1/quotes/{$this->w->B->submitted->quote_number}/attachments/{$this->w->A->att->public_id}");

    foreach ([$pdf, $file, $mixed] as $response) {
        expect($response->getStatusCode())->toBe(404)->and($response->getContent())->toBe('{"message":"Not found."}');
    }
});

it('keeps the two companies symmetric: A cannot reach B either', function () {
    $this->actingAs($this->w->a1);
    [, $url] = secMatrixTarget('GET api/v1/orders/{number}', $this->w, 'B');
    [, $invoiceUrl] = secMatrixTarget('GET api/v1/invoices/{invoice}', $this->w, 'B');

    $this->getJson($url)->assertNotFound()->assertExactJson(NOT_FOUND);
    $this->getJson($invoiceUrl)->assertNotFound()->assertExactJson(NOT_FOUND);
});
