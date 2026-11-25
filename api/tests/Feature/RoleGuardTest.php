<?php

it('guards /staff/ping by role', function (?string $role, int $status) {
    if ($role) {
        loginAs(makeUser($role));
    }

    $response = $this->getJson('/api/v1/staff/ping')->assertStatus($status);
    if ($status === 200) {
        $response->assertExactJson(['status' => 'ok']);
    }
})->with([
    'guest' => [null, 401],
    'customer' => ['customer', 403],
    'staff' => ['staff', 200],
    'admin' => ['admin', 200],
]);

it('guards /admin/ping by role', function (?string $role, int $status) {
    if ($role) {
        loginAs(makeUser($role));
    }

    $response = $this->getJson('/api/v1/admin/ping')->assertStatus($status);
    if ($status === 200) {
        $response->assertExactJson(['status' => 'ok']);
    }
})->with([
    'guest' => [null, 401],
    'customer' => ['customer', 403],
    'staff' => ['staff', 403],
    'admin' => ['admin', 200],
]);
