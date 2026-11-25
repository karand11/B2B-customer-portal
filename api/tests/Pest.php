<?php

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

const TEST_PASSWORD = 'Demo-Passw0rd!';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed(RolesSeeder::class);
        // Stateful (SPA cookie) mode needs an allowed Origin; always ask for JSON.
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Accept' => 'application/json']);
    })
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/** Create a factory user with the given role (customer gets a company, staff/admin none). */
function makeUser(string $role = 'customer', ?Company $company = null, array $attributes = []): User
{
    $factory = User::factory();

    return match ($role) {
        'customer' => $factory->customer($company)->create($attributes),
        'staff' => $factory->staff()->create($attributes),
        'admin' => $factory->admin()->create($attributes),
    };
}

/** Category by slug, created on first use (shared by catalog tests). */
function category(string $slug = 'deep-groove-ball'): Category
{
    return Category::where('slug', $slug)->first()
        ?? Category::factory()->create(['slug' => $slug, 'name' => ucfirst(str_replace('-', ' ', $slug))]);
}

/** Active product with explicit values; category defaults to deep-groove-ball. */
function makeProduct(string $partNumber, array $attributes = []): Product
{
    $attributes += ['category_id' => category()->id];

    return Product::factory()->create(['part_number' => $partNumber, 'name' => "Bearing {$partNumber}"] + $attributes);
}

/** @return list<string> */
function partNumbers(TestResponse $response): array
{
    return array_column($response->json('data'), 'part_number');
}

function loginAs(User $user): TestCase
{
    return test()->actingAs($user);
}

/**
 * Carry the session cookie of a response into following requests (the test client has no cookie jar).
 * Returns the plain session id.
 */
function carrySession(TestResponse $response): string
{
    $name = config('session.cookie');
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $name);
    expect($cookie)->not->toBeNull();

    $id = CookieValuePrefix::remove(decrypt($cookie->getValue(), false));
    test()->withCookie($name, $id);

    return $id;
}
