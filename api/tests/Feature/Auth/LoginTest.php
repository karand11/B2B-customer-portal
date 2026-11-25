<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->seed(DemoSeeder::class));

it('logs in a seeded customer, returns the exact UserResource and keeps the session for /me', function () {
    $alice = User::where('email', 'alice@northwind.test')->first();

    $response = $this->postJson('/api/v1/auth/login', ['email' => 'alice@northwind.test', 'password' => TEST_PASSWORD])
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $alice->id,
            'name' => 'Alice Customer',
            'email' => 'alice@northwind.test',
            'role' => 'customer',
            'company' => ['id' => $alice->company_id, 'name' => 'Northwind Machinery Ltd'],
        ]]);

    expect($response->headers->getCookies())->not->toBeEmpty();
    carrySession($response);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $alice->id);
});

it('returns an identical 422 for wrong password and unknown email', function () {
    $wrong = $this->postJson('/api/v1/auth/login', ['email' => 'alice@northwind.test', 'password' => 'wrong-password-1']);
    $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@northwind.test', 'password' => 'wrong-password-1']);

    $wrong->assertStatus(422)->assertJsonValidationErrors('email');
    expect($unknown->status())->toBe(422)
        ->and($unknown->json())->toBe($wrong->json())
        ->and($wrong->json('errors.email.0'))->toBe('These credentials do not match our records.');
});

it('normalises email case and whitespace on login', function () {
    $this->postJson('/api/v1/auth/login', ['email' => '  ALICE@Northwind.test ', 'password' => TEST_PASSWORD])
        ->assertOk()->assertJsonPath('data.email', 'alice@northwind.test');
});

it('regenerates the session id on login', function () {
    $before = carrySession($this->getJson('/sanctum/csrf-cookie')->assertNoContent());

    $after = carrySession(
        $this->postJson('/api/v1/auth/login', ['email' => 'alice@northwind.test', 'password' => TEST_PASSWORD])->assertOk()
    );

    expect($after)->not->toBe($before);
});

it('validates login input', function (array $body, array $errorKeys) {
    $this->postJson('/api/v1/auth/login', $body)
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonValidationErrors($errorKeys);
})->with([
    'missing email' => [['password' => 'x'], ['email']],
    'missing password' => [['email' => 'a@b.test'], ['password']],
    'malformed email' => [['email' => 'not-an-email', 'password' => 'x'], ['email']],
    'non-string password' => [['email' => 'a@b.test', 'password' => ['a']], ['password']],
    'array email' => [['email' => ['a@b.test'], 'password' => 'x'], ['email']],
    'email too long' => [['email' => str_repeat('a', 250).'@b.test', 'password' => 'x'], ['email']],
    'password too long' => [['email' => 'a@b.test', 'password' => str_repeat('a', 256)], ['password']],
    'empty body' => [[], ['email', 'password']],
]);

it('ignores tampered role and company_id on login', function () {
    $alice = User::where('email', 'alice@northwind.test')->first();
    $other = Company::where('name', 'Globex Industrial Supply')->first();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'alice@northwind.test',
        'password' => TEST_PASSWORD,
        'role' => 'admin',
        'company_id' => $other->id,
    ])->assertOk();
    carrySession($response);

    $this->getJson('/api/v1/me')
        ->assertJsonPath('data.role', 'customer')
        ->assertJsonPath('data.company.name', 'Northwind Machinery Ltd');

    expect($alice->fresh()->company_id)->toBe($alice->company_id)
        ->and($alice->fresh()->getRoleNames()->all())->toBe(['customer']);
});

it('does not expose a register route', function () {
    $before = User::count();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Eve', 'email' => 'eve@evil.test', 'password' => 'Passw0rd-12345', 'role' => 'admin',
    ])->assertNotFound();

    $this->postJson('/api/v1/auth/register')->assertNotFound();
    expect(User::count())->toBe($before)
        ->and(DB::table('users')->where('email', 'eve@evil.test')->exists())->toBeFalse();
});
