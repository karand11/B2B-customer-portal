<?php

use Database\Seeders\DemoSeeder;

it('invalidates the session on logout', function () {
    $this->seed(DemoSeeder::class);

    $login = $this->postJson('/api/v1/auth/login', ['email' => 'staff@portal.test', 'password' => TEST_PASSWORD])->assertOk();
    carrySession($login);
    $this->getJson('/api/v1/staff/ping')->assertOk();

    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    // The test app caches the resolved user on the guard; drop it so only the session counts.
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->getJson('/api/v1/staff/ping')->assertUnauthorized();
    expect(collect(array_keys(session()->all()))->contains(fn ($k) => str_starts_with($k, 'login_web_')))->toBeFalse();
});

it('rejects logout for guests', function () {
    $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
});
