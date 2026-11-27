<?php

use App\Models\Company;
use Illuminate\Support\Facades\Cache;

/*
 * Every string, number and boolean field of every back-office write: arrays, objects, nested arrays, numbers, booleans and null
 * answer 422 keyed by the field. Never 400 (Symfony throws on array input read through InputBag::get()) and never 500.
 * Field types: s = string, sn = nullable string, i = whole number, b = boolean, mm = millimetre value.
 */

const SHAPE_ENDPOINTS = [
    'POST /admin/products' => ['category_id' => 'i', 'part_number' => 's', 'name' => 's', 'description' => 'sn', 'brand' => 's', 'material' => 's', 'inner_diameter_mm' => 'mm',
        'outer_diameter_mm' => 'mm', 'width_mm' => 'mm', 'seal_type' => 's', 'stock_status' => 's', 'price_on_request' => 'b', 'price_cents' => 'i', 'active' => 'b'],
    'PUT /admin/products/{id}' => ['category_id' => 'i', 'part_number' => 's', 'name' => 's', 'description' => 'sn', 'brand' => 's', 'material' => 's', 'inner_diameter_mm' => 'mm',
        'outer_diameter_mm' => 'mm', 'width_mm' => 'mm', 'seal_type' => 's', 'stock_status' => 's', 'price_on_request' => 'b', 'price_cents' => 'i', 'version' => 'i'],
    'POST /admin/companies' => ['name' => 's', 'billing_address' => 'sn', 'tax_id' => 'sn'],
    'PUT /admin/companies/{id}' => ['name' => 's', 'billing_address' => 'sn', 'tax_id' => 'sn', 'version' => 'i'],
    'PUT /admin/companies/{id}/billing' => ['billing_address' => 'sn', 'tax_id' => 'sn', 'version' => 'i'],
    'POST /admin/users' => ['name' => 's', 'email' => 's', 'role' => 's', 'company_id' => 'i'],
    'PATCH /admin/users/{id}' => ['name' => 's'],
    'PUT /admin/users/{id}/assignment' => ['role' => 's', 'company_id' => 'i'],
    'POST /auth/accept-invitation' => ['token' => 's', 'email' => 's', 'password' => 's'],
];

/** @return array<string, array{string, string, string}> */
function shapeRows(): array
{
    $rows = [];
    foreach (SHAPE_ENDPOINTS as $endpoint => $fields) {
        foreach ($fields as $field => $type) {
            $rows["{$endpoint} {$field}"] = [$endpoint, $field, $type];
        }
    }

    return $rows;
}

/** @return array{string, string, array<string, mixed>} method, uri and a body that is valid for the endpoint */
function shapeRequest(string $endpoint, object $ctx): array
{
    [$method, $path] = explode(' ', $endpoint);
    $uri = '/api/v1'.str_replace('{id}', (string) match (true) {
        str_contains($endpoint, 'products') => $ctx->product->id,
        str_contains($endpoint, 'companies') => $ctx->company->id,
        default => $ctx->user->id,
    }, $path);

    $product = adminProductBody(['part_number' => 'SHAPE-NEW']) + ['active' => true];
    $body = match ($endpoint) {
        'POST /admin/products' => $product,
        'PUT /admin/products/{id}' => adminProductBody(['part_number' => 'SHAPE-1']) + ['version' => 1],
        'POST /admin/companies' => ['name' => 'Shape Co', 'billing_address' => null, 'tax_id' => null],
        'PUT /admin/companies/{id}' => ['name' => 'Shape Co', 'billing_address' => null, 'tax_id' => null, 'version' => 1],
        'PUT /admin/companies/{id}/billing' => ['billing_address' => null, 'tax_id' => null, 'version' => 1],
        'POST /admin/users' => ['name' => 'New Person', 'email' => 'new.person@acme.test', 'role' => 'staff', 'company_id' => null],
        'PATCH /admin/users/{id}' => ['name' => 'Renamed'],
        'PUT /admin/users/{id}/assignment' => ['role' => 'staff', 'company_id' => null],
        'POST /auth/accept-invitation' => ['token' => 'nope', 'email' => 'nobody@acme.test', 'password' => 'Long-Enough-1!', 'password_confirmation' => 'Long-Enough-1!'],
    };

    return [$method, $uri, $body];
}

beforeEach(function () {
    $this->admin = makeUser('admin');
    $this->ctx = (object) ['product' => makeProduct('SHAPE-1'), 'company' => Company::factory()->create(), 'user' => makeUser('staff')];
});

it('accepts a valid body on every endpoint, so a 422 below is about the field under test', function (string $endpoint) {
    [$method, $uri, $body] = shapeRequest($endpoint, $this->ctx);

    // The accept-invitation body is valid in shape but names no invitation: the same generic 422 as any bad link, keyed by email.
    $response = $this->actingAs($this->admin)->json($method, $uri, $body);

    expect($response->status())->toBeIn($endpoint === 'POST /auth/accept-invitation' ? [422] : [200, 201]);
})->with(array_map(fn ($e) => [$e], array_combine(array_keys(SHAPE_ENDPOINTS), array_keys(SHAPE_ENDPOINTS))));

it('answers 422 keyed by the field for arrays, objects, numbers, booleans and null', function (string $endpoint, string $field, string $type) {
    $bad = ['empty array' => [], 'list' => ['x'], 'object' => ['a' => 1], 'nested' => [['x' => [1]]]]
        + match ($type) {
            's' => ['number' => 123, 'float' => 1.5, 'true' => true, 'false' => false, 'null' => null],
            'sn' => ['number' => 123, 'float' => 1.5, 'true' => true, 'false' => false],
            'mm' => ['true' => true, 'null' => null],
            'i' => ['string' => 'abc', 'float' => 1.5, 'true' => true],
            'b' => [],
        };

    foreach ($bad as $label => $value) {
        [$method, $uri, $body] = shapeRequest($endpoint, $this->ctx);
        Cache::flush(); // the limiters count 422s too
        $response = $this->actingAs($this->admin)->json($method, $uri, [$field => $value] + $body);

        expect($response->status())->toBe(422, "{$endpoint} {$field} = {$label}: got {$response->status()}")
            ->and($response->json('errors'))->toHaveKey($field);
    }
})->with(shapeRows());

it('answers 422 keyed by the parameter for an array or object in the query string of every admin list', function (string $uri, string $param) {
    foreach (["{$param}[]=x", "{$param}[a]=1", "{$param}[a][b]=1"] as $query) {
        $response = $this->actingAs($this->admin)->getJson("{$uri}?{$query}");

        expect($response->status())->toBe(422, "{$uri}?{$query}")->and($response->json('errors'))->toHaveKey($param);
    }
})->with(function () {
    $params = [
        '/api/v1/admin/products' => ['q', 'sort', 'page', 'per_page', 'active', 'category', 'brand', 'stock_status'],
        '/api/v1/admin/companies' => ['q', 'sort', 'page', 'per_page'],
        '/api/v1/admin/users' => ['q', 'sort', 'page', 'per_page', 'role', 'status', 'company_id'],
        '/api/v1/admin/audit-log' => ['sort', 'page', 'per_page', 'actor_id', 'action', 'subject_type', 'subject_id', 'company_id'],
    ];
    foreach ($params as $uri => $names) {
        foreach ($names as $name) {
            yield "{$uri} {$name}" => [$uri, $name];
        }
    }
});
