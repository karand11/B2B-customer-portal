<?php

use Database\Seeders\DemoSeeder;

beforeEach(fn () => $this->seed(DemoSeeder::class));

function attemptLogin(string $email, string $password = 'wrong-password-1')
{
    return test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

it('locks out after 5 failures, even for the correct password, and recovers after the window', function () {
    foreach (range(1, 5) as $i) {
        attemptLogin('alice@northwind.test')->assertStatus(422);
    }

    $blocked = attemptLogin('alice@northwind.test')->assertStatus(429);
    expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0);

    attemptLogin('alice@northwind.test', TEST_PASSWORD)->assertStatus(429);

    // A different email from the same IP is not blocked by the per-email key.
    attemptLogin('bob@northwind.test')->assertStatus(422);

    $this->travel(61)->seconds();
    attemptLogin('alice@northwind.test', TEST_PASSWORD)->assertOk();
});

it('counts unknown emails like known ones', function () {
    foreach (range(1, 5) as $i) {
        attemptLogin('ghost@nowhere.test')->assertStatus(422);
    }

    attemptLogin('ghost@nowhere.test')->assertStatus(429)->assertHeader('Retry-After');
});

it('treats email case-insensitively for the lockout key', function () {
    foreach (range(1, 5) as $i) {
        attemptLogin(strtoupper('alice@northwind.test'))->assertStatus(422);
    }

    attemptLogin('alice@northwind.test', TEST_PASSWORD)->assertStatus(429);
});

it('resets the failure counter after a successful login', function () {
    foreach (range(1, 4) as $i) {
        attemptLogin('alice@northwind.test')->assertStatus(422);
    }
    attemptLogin('alice@northwind.test', TEST_PASSWORD)->assertOk();

    foreach (range(1, 4) as $i) {
        attemptLogin('alice@northwind.test')->assertStatus(422);
    }
});

it('limits login requests to 20 per minute per IP', function () {
    foreach (range(1, 20) as $i) {
        attemptLogin("user{$i}@nowhere.test")->assertStatus(422);
    }

    attemptLogin('user21@nowhere.test')->assertStatus(429)->assertHeader('Retry-After');
});

function attemptLoginFrom(string $ip, string $email, string $password = 'wrong-password-1')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

it('locks an email after 20 failures from varying IPs, even for the correct password', function () {
    foreach (range(1, 20) as $i) {
        attemptLoginFrom("10.0.0.{$i}", 'alice@northwind.test')->assertStatus(422);
    }

    $blocked = attemptLoginFrom('10.0.1.1', 'alice@northwind.test')->assertStatus(429);
    expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($blocked->json('message'))->toStartWith('Too many attempts. Please try again in ');

    attemptLoginFrom('10.0.1.2', 'alice@northwind.test', TEST_PASSWORD)->assertStatus(429);

    // Other emails are unaffected.
    attemptLoginFrom('10.0.1.3', 'bob@northwind.test', TEST_PASSWORD)->assertOk();
});

it('recovers from the email lockout after 15 minutes', function () {
    foreach (range(1, 20) as $i) {
        attemptLoginFrom("10.0.0.{$i}", 'alice@northwind.test')->assertStatus(422);
    }
    attemptLoginFrom('10.0.1.1', 'alice@northwind.test', TEST_PASSWORD)->assertStatus(429);

    $this->travel(14)->minutes();
    attemptLoginFrom('10.0.1.1', 'alice@northwind.test', TEST_PASSWORD)->assertStatus(429);

    $this->travel(61)->seconds();
    attemptLoginFrom('10.0.1.1', 'alice@northwind.test', TEST_PASSWORD)->assertOk();
});

it('counts unknown emails toward the email-only limit', function () {
    foreach (range(1, 20) as $i) {
        attemptLoginFrom("10.0.0.{$i}", 'ghost@nowhere.test')->assertStatus(422);
    }

    attemptLoginFrom('10.0.1.1', 'ghost@nowhere.test')->assertStatus(429)->assertHeader('Retry-After');
});

it('does not let a successful login from another IP reset the email-only counter', function () {
    foreach (range(1, 19) as $i) {
        attemptLoginFrom("10.0.0.{$i}", 'alice@northwind.test')->assertStatus(422);
    }
    attemptLoginFrom('10.0.2.1', 'alice@northwind.test', TEST_PASSWORD)->assertOk();
    attemptLoginFrom('10.0.2.2', 'alice@northwind.test')->assertStatus(422); // 20th failure

    attemptLoginFrom('10.0.2.3', 'alice@northwind.test', TEST_PASSWORD)->assertStatus(429);
});

it('shares one email+IP counter across an IPv6 /64', function () {
    $hosts = ['2001:db8:1:2::1', '2001:db8:1:2:aaaa::2', '2001:db8:1:2:ffff:ffff:ffff:ffff', '2001:db8:1:2::4', '2001:db8:1:2::5'];
    foreach ($hosts as $ip) {
        attemptLoginFrom($ip, 'alice@northwind.test')->assertStatus(422);
    }

    attemptLoginFrom('2001:db8:1:2::99', 'alice@northwind.test', TEST_PASSWORD)->assertStatus(429);
    // A different /64 is a different counter.
    attemptLoginFrom('2001:db8:1:3::1', 'alice@northwind.test', TEST_PASSWORD)->assertOk();
});

it('treats IPv4-mapped IPv6 like the plain IPv4 address', function () {
    foreach (range(1, 5) as $i) {
        attemptLoginFrom('::ffff:192.0.2.7', 'alice@northwind.test')->assertStatus(422);
    }

    attemptLoginFrom('192.0.2.7', 'alice@northwind.test', TEST_PASSWORD)->assertStatus(429);
});
