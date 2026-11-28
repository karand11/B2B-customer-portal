<?php

const SEC_WINDOW_MESSAGE = 'Only the first 10,000 results can be paged through. Narrow the search with filters.';

/** Every list endpoint: [label => [uri, role]]. */
function secListRoutes(): array
{
    return [
        'products' => ['/api/v1/products', 'customer'],
        'orders (customer)' => ['/api/v1/orders', 'customer'],
        'orders (staff)' => ['/api/v1/orders', 'staff'],
        'quotes (customer)' => ['/api/v1/quotes', 'customer'],
        'quotes (staff)' => ['/api/v1/quotes', 'staff'],
        'invoices (customer)' => ['/api/v1/invoices', 'customer'],
        'invoices (staff)' => ['/api/v1/invoices', 'staff'],
        'admin products' => ['/api/v1/admin/products', 'staff'],
        'admin companies' => ['/api/v1/admin/companies', 'staff'],
        'admin users' => ['/api/v1/admin/users', 'admin'],
        'admin audit log' => ['/api/v1/admin/audit-log', 'admin'],
    ];
}

it('serves page * per_page up to exactly 10,000 and refuses more with 422 on `page`', function (string $uri, string $role) {
    $this->actingAs(makeUser($role));

    // page * per_page = 10,000 exactly, in three shapes (the highest page every list accepts is 10,000).
    foreach ([[100, 100], [200, 50], [10000, 1]] as [$page, $perPage]) {
        $this->getJson("{$uri}?page={$page}&per_page={$perPage}")->assertOk();
    }

    // 10,001 and more.
    foreach ([[101, 100], [201, 50], [5001, 2]] as [$page, $perPage]) {
        $response = $this->getJson("{$uri}?page={$page}&per_page={$perPage}")->assertStatus(422);

        expect($response->json())->toBe(['message' => SEC_WINDOW_MESSAGE, 'errors' => ['page' => [SEC_WINDOW_MESSAGE]]]);
    }
})->with(function () {
    foreach (secListRoutes() as $label => [$uri, $role]) {
        yield $label => [$uri, $role];
    }
});

it('allows per_page 24 up to page 416 and refuses page 417 (the contract example)', function () {
    $this->actingAs(makeUser('customer'));

    $this->getJson('/api/v1/products?page=416&per_page=24')->assertOk();
    $this->getJson('/api/v1/products?page=417&per_page=24')->assertStatus(422)->assertJsonValidationErrors('page');
});

it('applies the window to the DEFAULT per_page too, when the window is configured small', function () {
    config(['portal.max_window' => 40]);
    $this->actingAs(makeUser('customer'));

    $this->getJson('/api/v1/orders?page=2')->assertOk();
    $this->getJson('/api/v1/orders?page=3')->assertStatus(422)->assertJsonPath('errors.page.0', 'Only the first 40 results can be paged through. Narrow the search with filters.');
});

it('keeps the real total and last page in meta, and answers a page past the end inside the window with an empty list', function () {
    $w = orderWorld();
    placeOrderAs($w->a1, [[makeProduct('PW-1', ['price_cents' => 100]), 1]]);
    $this->actingAs($w->a1);

    $response = $this->getJson('/api/v1/orders?page=100&per_page=100')->assertOk();

    expect($response->json('data'))->toBe([])->and($response->json('meta.total'))->toBe(1)->and($response->json('meta.last_page'))->toBe(1);
});

it('keeps the individual errors of malformed paging values (the window never hides them)', function (string $query) {
    $this->actingAs(makeUser('customer'));

    $response = $this->getJson("/api/v1/orders?{$query}")->assertStatus(422);

    expect($response->json('errors.page.0') ?? $response->json('errors.per_page.0'))->not->toBe(SEC_WINDOW_MESSAGE);
})->with(['page=0', 'page=-1', 'page=abc', 'per_page=0', 'per_page=101', 'per_page=abc&page=500', 'page=99999&per_page=100']);

it('does not apply the window to requests without paging parameters', function (string $uri, string $role) {
    $this->actingAs(makeUser($role));

    $this->getJson($uri)->assertOk();
})->with(function () {
    foreach (secListRoutes() as $label => [$uri, $role]) {
        yield $label => [$uri, $role];
    }
});
