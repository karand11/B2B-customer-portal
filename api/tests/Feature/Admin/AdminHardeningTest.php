<?php

use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\CompanyAdmin;
use App\Services\ProductAdmin;
use App\Services\UserAdmin;
use App\Services\UserInvitations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Mail::fake();
    $this->admin = makeUser('admin');
    $this->company = Company::factory()->create();
});

it('answers a database error on an admin endpoint with the generic 500 and leaks nothing', function () {
    $marker = 'LEAK-MARKER-9137';
    DB::statement("ALTER TABLE products ADD CONSTRAINT products_tmp_leak CHECK (name <> '{$marker}')");
    Log::spy();

    $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/products', adminProductBody(['name' => $marker]));

    $response->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
    expect($response->getContent())->not->toContain($marker)->not->toContain('products_tmp_leak')->not->toContain('SQLSTATE')->not->toContain('insert into');
    expect(Product::count())->toBe(0);
});

it('never answers 500 to hostile bodies on any admin write endpoint', function () {
    $product = makeProduct('HOSTILE-1');
    $user = makeUser('customer', $this->company);
    $pending = makeUser('customer', $this->company, ['invited_at' => now(), 'invitation_accepted_at' => null]);
    $targets = [
        ['POST', '/api/v1/admin/products'], ['PUT', "/api/v1/admin/products/{$product->id}"], ['POST', "/api/v1/admin/products/{$product->id}/activate"],
        ['POST', '/api/v1/admin/products/imports'], ['POST', '/api/v1/admin/products/imports/00000000-0000-4000-8000-000000000000/apply'],
        ['POST', '/api/v1/admin/companies'], ['PUT', "/api/v1/admin/companies/{$this->company->id}"], ['PUT', "/api/v1/admin/companies/{$this->company->id}/billing"],
        ['POST', '/api/v1/admin/users'], ['PATCH', "/api/v1/admin/users/{$user->id}"], ['PUT', "/api/v1/admin/users/{$user->id}/assignment"],
        ['POST', "/api/v1/admin/users/{$user->id}/password-reset"], ['POST', "/api/v1/admin/users/{$pending->id}/invitation"],
        ['POST', '/api/v1/auth/accept-invitation'],
    ];
    $bodies = [
        'not json', '[]', '{"a":', '"string"', '123', 'null', 'true', '{"name":["x"],"version":{"a":1},"role":[],"company_id":[1],"email":{"x":1}}',
        '{"company_id":99999999999999999999999,"version":99999999999999999999999,"price_cents":1e400,"inner_diameter_mm":1e999}',
        '{"name":"'.str_repeat('x', 300000).'"}', '{"part_number":"\u0000","name":"\ud800","description":"\udfff","role":"admin\u0000"}',
        '{"billing_address":'.str_repeat('[', 200).str_repeat(']', 200).'}', '{"skip_invalid":[],"token":[],"email":[],"password":[]}',
    ];

    foreach ($targets as [$method, $uri]) {
        foreach ($bodies as $raw) {
            Cache::flush();
            $status = $this->actingAs($this->admin)->call($method, $uri, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)->getStatusCode();

            expect($status)->toBeLessThan(500, "{$method} {$uri} with ".substr($raw, 0, 40));
        }
    }
});

it('locks the rows it changes: every admin service takes FOR UPDATE inside its transaction', function () {
    $actor = makeUser('staff');
    $product = makeProduct('LOCK-1')->fresh();
    $company = Company::factory()->create()->fresh();
    $user = makeUser('customer', $this->company);
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = strtolower($query->sql);
    });
    $locked = function (callable $action) use (&$statements) {
        $statements = [];
        $action();

        return count(array_filter($statements, fn ($sql) => str_contains($sql, 'for update')));
    };

    expect($locked(fn () => app(ProductAdmin::class)->update($actor, $product, ['name' => 'Locked'] + $product->only(['category_id', 'part_number', 'brand', 'inner_diameter_mm', 'outer_diameter_mm', 'width_mm', 'material', 'seal_type', 'price_cents', 'price_on_request', 'stock_status']), $product->version)))->toBeGreaterThanOrEqual(1)
        ->and($locked(fn () => app(ProductAdmin::class)->setActive($actor, $product, false)))->toBeGreaterThanOrEqual(1)
        ->and($locked(fn () => app(CompanyAdmin::class)->update($actor, $company, ['billing_address' => 'Locked 1', 'tax_id' => null], $company->version)))->toBeGreaterThanOrEqual(1)
        ->and($locked(fn () => app(UserAdmin::class)->deactivate($this->admin, $user)))->toBeGreaterThanOrEqual(1) // the user row (the admin set is serialised by the advisory lock, see ConcurrencyTest)
        ->and($locked(fn () => app(UserAdmin::class)->assign($this->admin, $user, 'staff', null)))->toBeGreaterThanOrEqual(1)
        ->and($locked(fn () => app(UserInvitations::class)->invite($this->admin, ['name' => 'N', 'email' => 'n@acme.test', 'role' => 'staff', 'company_id' => null])))->toBe(0)
        ->and($locked(fn () => app(UserInvitations::class)->revoke($this->admin, User::where('email', 'n@acme.test')->first())))->toBeGreaterThanOrEqual(1);
});

it('refuses a stale version even when a second writer changed the row between the read and the write', function () {
    $product = makeProduct('RACE-1', ['price_cents' => 1000]);
    $loaded = $product->fresh(); // what the first editor sees: version 1
    DB::table('products')->where('id', $product->id)->update(['price_cents' => 2000]); // the second editor saved first

    $attributes = ['name' => 'First editor', 'part_number' => 'RACE-1'] + $loaded->only(['category_id', 'brand', 'inner_diameter_mm', 'outer_diameter_mm', 'width_mm', 'material', 'seal_type', 'price_cents', 'price_on_request', 'stock_status']);
    expect(fn () => app(ProductAdmin::class)->update($this->admin, $loaded, $attributes, $loaded->version))->toThrow(ValidationException::class);

    expect($product->fresh()->price_cents)->toBe(2000)->and($product->fresh()->name)->not->toBe('First editor');
});

it('keeps the catalog list of customers identical whatever the back office does to inactive products', function () {
    $customer = makeUser('customer', $this->company);
    $keep = makeProduct('PUB-1');
    $hide = makeProduct('PUB-2');
    $before = partNumbers($this->actingAs($customer)->getJson('/api/v1/products'));

    $this->actingAs($this->admin)->postJson("/api/v1/admin/products/{$hide->id}/deactivate")->assertOk();
    $this->actingAs($this->admin)->postJson("/api/v1/admin/products/{$hide->id}/activate")->assertOk();

    expect(partNumbers($this->actingAs($customer)->getJson('/api/v1/products')))->toBe($before)->and($keep->fresh()->version)->toBe(1);
});

it('has a policy for every back-office model and the matrix: customers nothing, staff the daily work, admins everything', function () {
    $matrix = [
        // [ability, subject, customer, staff, admin]
        ['viewAny', Product::class, false, true, true], ['create', Product::class, false, true, true], ['import', Product::class, false, true, true],
        ['update', makeProduct('POL-1'), false, true, true], ['activate', makeProduct('POL-2'), false, true, true],
        ['viewAny', Company::class, false, true, true], ['updateBilling', $this->company, false, true, true],
        ['create', Company::class, false, false, true], ['update', $this->company, false, false, true],
        ['viewAny', User::class, false, false, true], ['create', User::class, false, false, true], ['assign', $this->admin, false, false, true],
        ['deactivate', $this->admin, false, false, true], ['resetPassword', $this->admin, false, false, true], ['manageInvitation', $this->admin, false, false, true],
        ['viewAny', AuditLogEntry::class, false, false, true],
    ];

    foreach (['customer' => makeUser('customer', $this->company), 'staff' => makeUser('staff'), 'admin' => makeUser('admin')] as $role => $who) {
        foreach ($matrix as [$ability, $subject, $customer, $staff, $admin]) {
            $expected = compact('customer', 'staff', 'admin')[$role];

            expect(Gate::forUser($who)->allows($ability, $subject))->toBe($expected, "{$role} {$ability} ".(is_string($subject) ? $subject : $subject::class));
        }
    }
});
