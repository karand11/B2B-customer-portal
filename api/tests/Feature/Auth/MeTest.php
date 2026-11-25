<?php

use App\Models\Company;

it('returns 401 JSON without a redirect for guests', function () {
    $response = $this->getJson('/api/v1/me')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);

    expect($response->headers->has('Location'))->toBeFalse();
});

it('returns exactly the contract keys for a customer', function () {
    $user = makeUser('customer');
    loginAs($user);

    $json = $this->getJson('/api/v1/me')->assertOk()->json();

    expect(array_keys($json))->toBe(['data'])
        ->and(array_keys($json['data']))->toBe(['id', 'name', 'email', 'role', 'company'])
        ->and(array_keys($json['data']['company']))->toBe(['id', 'name'])
        ->and($json['data']['id'])->toBeInt()
        ->and($json['data']['company']['id'])->toBeInt()
        ->and($json['data']['role'])->toBe('customer');
});

it('returns company null for staff and admin', function (string $role) {
    loginAs(makeUser($role));

    $this->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.company', null)
        ->assertJsonPath('data.role', $role);
})->with(['staff', 'admin']);

it('isolates customers of different companies and ignores query tampering', function () {
    $a = makeUser('customer', Company::factory()->create(['name' => 'Northwind Machinery Ltd']));
    $b = makeUser('customer', Company::factory()->create(['name' => 'Globex Industrial Supply']));

    loginAs($a);
    $this->getJson('/api/v1/me')->assertJsonPath('data.id', $a->id)->assertJsonPath('data.company.id', $a->company_id);
    $this->getJson("/api/v1/me?id={$b->id}&company_id={$b->company_id}")
        ->assertJsonPath('data.id', $a->id)
        ->assertJsonPath('data.company.name', 'Northwind Machinery Ltd');

    loginAs($b);
    $this->getJson('/api/v1/me')->assertJsonPath('data.id', $b->id)->assertJsonPath('data.company.name', 'Globex Industrial Supply');
});

it('gives two customers of one company the same company but their own id', function () {
    $company = Company::factory()->create();
    $one = makeUser('customer', $company);
    $two = makeUser('customer', $company);

    loginAs($one);
    $first = $this->getJson('/api/v1/me')->json('data');
    loginAs($two);
    $second = $this->getJson('/api/v1/me')->json('data');

    expect($first['company']['id'])->toBe($second['company']['id'])
        ->and($first['id'])->toBe($one->id)
        ->and($second['id'])->toBe($two->id);
});
