<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-27 10:00:00', 'UTC'));
});

afterEach(fn () => $this->travelBack());

it('keeps 60 requests a minute for customers and 240 for staff and admin, one bucket per user', function () {
    $customer = makeUser('customer');
    $staff = makeUser('staff');
    $admin = makeUser('admin');

    foreach (range(1, 60) as $i) {
        $this->actingAs($customer)->getJson('/api/v1/me')->assertOk();
    }
    $this->actingAs($customer)->getJson('/api/v1/me')->assertStatus(429)->assertHeader('Retry-After');

    foreach (range(1, 240) as $i) {
        $this->actingAs($staff)->getJson('/api/v1/me')->assertOk();
    }
    $this->actingAs($staff)->getJson('/api/v1/me')->assertStatus(429)->assertHeader('Retry-After');

    // Another staff user and an admin have their own buckets.
    $this->actingAs($admin)->getJson('/api/v1/me')->assertOk();
    $this->actingAs(makeUser('staff'))->getJson('/api/v1/me')->assertOk();
});

it('counts every back-office write against 60 a minute, 404s and 422s included', function () {
    $staff = makeUser('staff');

    foreach (range(1, 30) as $i) {
        $this->actingAs($staff)->postJson('/api/v1/admin/products', [])->assertStatus(422);
        $this->actingAs($staff)->postJson('/api/v1/admin/products/999999999/activate')->assertNotFound();
    }

    $this->actingAs($staff)->postJson('/api/v1/admin/products', [])->assertStatus(429)->assertHeader('Retry-After');
    $this->actingAs($staff)->getJson('/api/v1/admin/products')->assertOk(); // reads are not writes

    $this->travelTo(now()->addSeconds(61));
    $this->actingAs($staff)->postJson('/api/v1/admin/products', [])->assertStatus(422);
});

it('answers the role 403 before any limiter, for every attempt', function () {
    $customer = makeUser('customer');
    $staff = makeUser('staff');

    foreach (range(1, 70) as $i) {
        $this->actingAs($customer)->postJson('/api/v1/admin/products', [])->assertForbidden();
        $this->actingAs($staff)->postJson('/api/v1/admin/users', [])->assertForbidden();
    }
    // Nothing above counted: the customer still has his whole 60 a minute.
    $this->actingAs($customer)->getJson('/api/v1/me')->assertOk();
});

it('ignores a forged role, company, active flag or version on every staff-reachable endpoint', function () {
    $staff = makeUser('staff');
    $company = Company::factory()->create();
    $product = makeProduct('MA-1');
    $forged = ['role' => 'admin', 'company_id' => $company->id, 'active' => false, 'session_epoch' => 77, 'password' => 'Hacked-Passw0rd!', 'email' => 'x@evil.test', 'version' => 5];
    $this->actingAs($staff);

    $this->postJson('/api/v1/admin/products', $forged)->assertStatus(422);
    $this->putJson("/api/v1/admin/products/{$product->id}", $forged)->assertStatus(422);
    $this->putJson("/api/v1/admin/companies/{$company->id}/billing", ['billing_address' => 'x', 'tax_id' => null] + $forged)->assertStatus(409);
    $this->postJson("/api/v1/admin/products/{$product->id}/deactivate", $forged)->assertOk();
    $this->postJson('/api/v1/admin/products/imports', $forged)->assertStatus(422);
    $this->getJson('/api/v1/admin/products?role=admin&active=true')->assertOk();

    $fresh = User::with('roles')->find($staff->id);
    expect($fresh->primaryRole())->toBe('staff')->and($fresh->company_id)->toBeNull()->and($fresh->active)->toBeTrue()->and($fresh->session_epoch)->toBe($staff->session_epoch)
        ->and($fresh->email)->toBe($staff->email)->and(Product::find($product->id)->active)->toBeFalse();
});

it('applies the contract limiter names and limits', function () {
    foreach (['admin-writes' => 60, 'admin-import' => 5, 'admin-import-hourly' => 20, 'admin-invites' => 20] as $name => $max) {
        $request = Request::create('/');
        $request->setUserResolver(fn () => makeUser('staff'));

        expect(RateLimiter::limiter($name)($request)->maxAttempts)->toBe($max, $name);
    }
});
