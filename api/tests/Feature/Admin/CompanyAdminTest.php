<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-27 12:00:00', 'UTC'));
    $this->staff = makeUser('staff');
    $this->admin = makeUser('admin');
});

afterEach(fn () => $this->travelBack());

function adminBilling(Company $company, array $overrides = [], ?int $version = null): array
{
    return array_replace(['billing_address' => "1 Main Street\nSampletown 00000", 'tax_id' => 'TAX-1', 'version' => $version ?? $company->fresh()->version], $overrides);
}

it('lists companies with the contract keys, search, sort and pagination', function () {
    Company::factory()->create(['name' => 'Beta 100% Parts', 'billing_address' => null]);
    Company::factory()->create(['name' => 'Alpha Bearings']);
    Company::factory()->create(['name' => 'Gamma_Works']);
    $this->actingAs($this->staff);
    $names = fn (string $q = '') => array_column($this->getJson('/api/v1/admin/companies'.$q)->assertOk()->json('data'), 'name');

    $response = $this->getJson('/api/v1/admin/companies')->assertOk();
    expect(array_keys($response->json('data.0')))->toBe(ADMIN_COMPANY_KEYS)->and(array_keys($response->json('meta')))->toBe(['current_page', 'last_page', 'per_page', 'total', 'from', 'to'])
        ->and($response->json('meta.per_page'))->toBe(25);

    expect($names())->toBe(['Alpha Bearings', 'Beta 100% Parts', 'Gamma_Works'])
        ->and($names('?sort=-name'))->toBe(['Gamma_Works', 'Beta 100% Parts', 'Alpha Bearings'])
        ->and($names('?q=ALPHA'))->toBe(['Alpha Bearings'])
        ->and($names('?q=100%25'))->toBe(['Beta 100% Parts'])
        ->and($names('?q=%25'))->toBe(['Beta 100% Parts'])
        ->and($names('?q=_'))->toBe(['Gamma_Works'])
        ->and($names('?q=a_p'))->toBe([])
        ->and($names('?per_page=2&page=2'))->toBe(['Gamma_Works'])
        ->and($names('?sort=created_at'))->toBe(['Beta 100% Parts', 'Alpha Bearings', 'Gamma_Works']);
    expect(collect($this->getJson('/api/v1/admin/companies?q=Beta')->json('data'))->first()['has_billing_address'])->toBeFalse();
});

it('refuses bad list parameters on the parameter name', function () {
    $this->actingAs($this->staff);

    foreach (['sort=bogus', 'sort=-id', 'per_page=101', 'page=0', 'q[]=x', 'sort[]=name'] as $bad) {
        $this->getJson("/api/v1/admin/companies?{$bad}")->assertStatus(422)->assertJsonValidationErrors(explode('=', explode('[', $bad)[0])[0]);
    }
    $this->getJson('/api/v1/admin/companies?q=a%00b')->assertStatus(422)->assertJsonValidationErrors('q');
});

it('lists with a constant number of queries', function () {
    $this->actingAs($this->staff);
    $count = fn () => adminQueryCount(fn () => $this->getJson('/api/v1/admin/companies?per_page=100')->assertOk());
    $count(); // warm-up
    Company::factory()->count(3)->create();
    $few = $count();
    Company::factory()->count(60)->create();

    expect($count())->toBe($few)->and($few)->toBeLessThanOrEqual(4); // roles, count, rows
});

it('counts users, open orders, open quotes and unpaid invoices by their effective status', function () {
    $w = quoteWorld();
    $other = Company::factory()->create();
    foreach (['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'] as $status) {
        orderWith($w->a, [[100, 1]], $status);
    }
    // Each invoice needs an order: a delivered one, so it does not count as open.
    $invoiceFor = fn () => Invoice::factory()->forOrder(orderWith($w->a, [[100, 1]], 'delivered'));
    orderWith($other, [[100, 1]], 'pending');

    foreach (['submitted', 'inReview', 'quoted', 'accepted', 'declined'] as $state) {
        makeQuote($w, $state);
    }
    makeQuote($w, 'quoted', ['valid_until' => '2026-11-26']); // stored quoted but past valid_until: expired, not open
    makeQuote($w, 'quoted', ['valid_until' => '2026-11-27']); // valid through today: still open
    makeQuote($w, 'expired');

    $invoiceFor()->open()->issuedOn('2026-11-01')->dueOn('2026-12-31')->create();
    $invoiceFor()->issuedOn('2026-10-01')->dueOn('2026-11-01')->create(); // stored open, past due: effectively overdue, still unpaid
    $invoiceFor()->overdue()->create();
    $invoiceFor()->paid()->create();
    $invoiceFor()->void()->create();
    makeUser('customer', $w->a, ['active' => false]);

    $data = $this->actingAs($this->staff)->getJson("/api/v1/admin/companies/{$w->a->id}")->assertOk()->json('data');

    // Every user of the company in any state (the factories add a creator per order). Open orders: pending, confirmed, shipped and the order the accepted quote created.
    expect($data['users_count'])->toBe(User::where('company_id', $w->a->id)->count())->and($data['users_count'])->toBeGreaterThan(3)
        ->and($data['open_orders_count'])->toBe(4)->and($data['open_quotes_count'])->toBe(4)
        ->and($data['unpaid_invoices_count'])->toBe(3);
    $theirs = $this->getJson("/api/v1/admin/companies/{$other->id}")->json('data');
    expect($theirs['open_orders_count'])->toBe(1)->and($theirs['users_count'])->toBe(User::where('company_id', $other->id)->count())->and($theirs['open_quotes_count'])->toBe(0);
});

it('returns the detail form with billing address, users and recent documents', function () {
    $w = quoteWorld();
    foreach (range(1, 6) as $i) {
        Invoice::factory()->forOrder(orderWith($w->a, [[100, 1]], 'delivered'))->open()->create();
    }
    foreach (range(1, 6) as $i) {
        orderWith($w->a, [[100 * $i, 1]], 'pending');
    }
    foreach (range(1, 6) as $i) {
        makeQuote($w, 'submitted');
    }
    $w->a->forceFill(['billing_address' => "Line 1\nLine 2"])->save();

    $data = $this->actingAs($this->staff)->getJson("/api/v1/admin/companies/{$w->a->id}")->assertOk()->json('data');

    expect(array_keys($data))->toBe([...ADMIN_COMPANY_KEYS, 'billing_address', 'users', 'recent'])
        ->and($data['billing_address'])->toBe("Line 1\nLine 2")->and($data['has_billing_address'])->toBeTrue()
        ->and(array_keys($data['users'][0]))->toBe(['id', 'name', 'email', 'role', 'status'])
        ->and(count($data['users']))->toBe(User::where('company_id', $w->a->id)->count())->and(array_unique(array_column($data['users'], 'role')))->toBe(['customer'])
        ->and(array_keys($data['recent']))->toBe(['orders', 'quotes', 'invoices'])
        ->and($data['recent']['orders'])->toHaveCount(5)->and($data['recent']['quotes'])->toHaveCount(5)->and($data['recent']['invoices'])->toHaveCount(5)
        ->and(array_keys($data['recent']['orders'][0]))->toBe(['number', 'status', 'total_cents', 'currency', 'created_at'])
        ->and(array_keys($data['recent']['quotes'][0]))->toBe(['number', 'status', 'created_at'])
        ->and(array_keys($data['recent']['invoices'][0]))->toBe(['id', 'number', 'status', 'total_cents', 'currency', 'due_date'])
        ->and($data['recent']['orders'][0]['total_cents'])->toBe(600);
    $this->getJson('/api/v1/admin/companies/999999')->assertNotFound()->assertExactJson(NOT_FOUND);
});

it('shows a company detail in at most 8 queries however much data it has', function () {
    $w = quoteWorld();
    foreach (range(1, 12) as $i) {
        orderWith($w->a, [[100, 1]], 'pending');
        makeQuote($w, 'submitted');
    }

    $this->actingAs($this->staff);
    $request = fn () => $this->getJson("/api/v1/admin/companies/{$w->a->id}")->assertOk();
    $request(); // warm-up

    expect(adminQueryCount($request))->toBeLessThanOrEqual(8);
});

it('lets only admins create and rename companies, and writes audit rows', function () {
    $body = ['name' => 'New Customer Ltd', 'billing_address' => "9 Road\nTown", 'tax_id' => 'NC-1'];

    $this->actingAs($this->staff)->postJson('/api/v1/admin/companies', $body)->assertForbidden();
    $created = $this->actingAs($this->admin)->postJson('/api/v1/admin/companies', $body)->assertCreated();
    $id = $created->json('data.id');

    expect($created->json('data'))->toMatchArray(['name' => 'New Customer Ltd', 'tax_id' => 'NC-1', 'has_billing_address' => true, 'version' => 1, 'billing_address' => "9 Road\nTown", 'users_count' => 0]);
    auditLogExpect(auditLogOne('company.created'), $this->admin, 'company', (string) $id, $id, ['before' => [], 'after' => ['name' => 'New Customer Ltd', 'tax_id' => 'NC-1']], ['billing_address_changed' => true]);

    $company = Company::findOrFail($id);
    $this->actingAs($this->staff)->putJson("/api/v1/admin/companies/{$id}", adminBilling($company, ['name' => 'Renamed']))->assertForbidden();
    $this->actingAs($this->admin)->putJson("/api/v1/admin/companies/{$id}", adminBilling($company, ['name' => 'Renamed Ltd', 'billing_address' => "9 Road\nTown", 'tax_id' => 'NC-1']))->assertOk()
        ->assertJsonPath('data.name', 'Renamed Ltd')->assertJsonPath('data.version', 2);

    $row = auditLogOne('company.updated');
    auditLogExpect($row, $this->admin, 'company', (string) $id, $id, ['before' => ['name' => 'New Customer Ltd'], 'after' => ['name' => 'Renamed Ltd']], ['billing_address_changed' => false]);
    expect($row['subject_label'])->toBe('Renamed Ltd');
});

it('allows two companies with the same name', function () {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/admin/companies', ['name' => 'Twin Ltd'])->assertCreated();
    $this->postJson('/api/v1/admin/companies', ['name' => 'Twin Ltd'])->assertCreated();

    expect(Company::where('name', 'Twin Ltd')->count())->toBe(2);
});

it('lets staff and admin edit the billing address and tax id but not the name', function (string $role) {
    $company = Company::factory()->create(['name' => 'Keep Name', 'billing_address' => 'Old', 'tax_id' => 'OLD-1']);

    $response = $this->actingAs($this->{$role})->putJson("/api/v1/admin/companies/{$company->id}/billing", adminBilling($company, ['name' => 'Hacked', 'billing_address' => "New 1\r\n  New 2  \r\n\r\nNew 3"]))->assertOk();

    expect($response->json('data'))->toMatchArray(['name' => 'Keep Name', 'billing_address' => "New 1\nNew 2\nNew 3", 'tax_id' => 'TAX-1', 'version' => 2]);
    $row = auditLogOne('company.updated');
    auditLogExpect($row, $this->{$role}, 'company', (string) $company->id, $company->id, ['before' => ['tax_id' => 'OLD-1'], 'after' => ['tax_id' => 'TAX-1']], ['billing_address_changed' => true]);
    expect(json_encode($row))->not->toContain('New 1');
})->with(['staff', 'admin']);

it('clears the billing address and tax id with null or empty strings', function () {
    $company = Company::factory()->create(['billing_address' => 'Old', 'tax_id' => 'OLD-1']);

    $this->actingAs($this->staff)->putJson("/api/v1/admin/companies/{$company->id}/billing", adminBilling($company, ['billing_address' => '', 'tax_id' => null]))->assertOk()
        ->assertJsonPath('data.billing_address', null)->assertJsonPath('data.has_billing_address', false)->assertJsonPath('data.tax_id', null);
    expect($company->fresh()->billing_address)->toBeNull();
});

it('validates the billing rules', function (array $override, string $field) {
    $company = Company::factory()->create();

    $this->actingAs($this->staff)->putJson("/api/v1/admin/companies/{$company->id}/billing", adminBilling($company, $override))->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'seven lines' => [['billing_address' => implode("\n", array_fill(0, 7, 'x'))], 'billing_address'],
    '81 char line' => [['billing_address' => str_repeat('a', 81)], 'billing_address'],
    'control char' => [['billing_address' => "Line\x07"], 'billing_address'],
    'zero width' => [['billing_address' => "Li\u{200B}ne"], 'billing_address'],
    'lone CR' => [['billing_address' => "a\rb"], 'billing_address'],
    'address array' => [['billing_address' => ['x']], 'billing_address'],
    'tax id 33 chars' => [['tax_id' => str_repeat('T', 33)], 'tax_id'],
    'tax id symbol' => [['tax_id' => 'TAX<1>'], 'tax_id'],
    'tax id leading dash' => [['tax_id' => '-TAX'], 'tax_id'],
    'version missing' => [['version' => null], 'version'],
    'version text' => [['version' => 'x'], 'version'],
]);

it('accepts six lines within 500 characters', function () {
    $company = Company::factory()->create();
    $address = implode("\n", [str_repeat('a', 80), str_repeat('b', 80), str_repeat('c', 80), str_repeat('d', 80), str_repeat('e', 80), str_repeat('f', 80)]);

    expect(strlen($address))->toBe(485);
    $this->actingAs($this->staff)->putJson("/api/v1/admin/companies/{$company->id}/billing", adminBilling($company, ['billing_address' => $address, 'tax_id' => str_repeat('T', 32)]))->assertOk();
});

it('requires every key of the full company update and validates the name', function (array $override, string $field) {
    $company = Company::factory()->create();

    $this->actingAs($this->admin)->putJson("/api/v1/admin/companies/{$company->id}", adminBilling($company, $override + ['name' => 'Ok']))->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'name empty' => [['name' => ''], 'name'],
    'name 256' => [['name' => str_repeat('n', 256)], 'name'],
    'name control' => [['name' => "a\x07b"], 'name'],
    'name null' => [['name' => null], 'name'],
]);

it('requires billing_address, tax_id and version keys on the full update and on the billing update', function () {
    $company = Company::factory()->create();
    $this->actingAs($this->admin);

    $this->putJson("/api/v1/admin/companies/{$company->id}", ['name' => 'X', 'version' => 1])->assertStatus(422)->assertJsonValidationErrors(['billing_address', 'tax_id']);
    $this->putJson("/api/v1/admin/companies/{$company->id}/billing", ['version' => 1])->assertStatus(422)->assertJsonValidationErrors(['billing_address', 'tax_id']);
    $this->postJson('/api/v1/admin/companies', [])->assertStatus(422)->assertJsonValidationErrors('name');
});

it('answers 409 for a stale version on both update endpoints and writes nothing', function () {
    $company = Company::factory()->create(['name' => 'Stale Ltd']);
    $this->actingAs($this->staff)->putJson("/api/v1/admin/companies/{$company->id}/billing", adminBilling($company, [], 1))->assertOk();

    $expected = ['message' => 'This record was changed by someone else. Reload it and try again.', 'errors' => ['version' => ['This record was changed by someone else. Reload it and try again.']]];
    $this->putJson("/api/v1/admin/companies/{$company->id}/billing", adminBilling($company, ['tax_id' => 'LATE'], 1))->assertStatus(409)->assertExactJson($expected);
    $this->actingAs($this->admin)->putJson("/api/v1/admin/companies/{$company->id}", adminBilling($company, ['name' => 'Late'], 1))->assertStatus(409)->assertExactJson($expected);

    expect($company->fresh()->name)->toBe('Stale Ltd')->and($company->fresh()->tax_id)->toBe('TAX-1')->and(auditLogRows('company.updated'))->toHaveCount(1);
});

it('does not write, bump or audit a company update that changes nothing', function () {
    $company = Company::factory()->create(['billing_address' => "Same 1\nSame 2", 'tax_id' => 'SAME']);

    $this->actingAs($this->staff)->putJson("/api/v1/admin/companies/{$company->id}/billing", adminBilling($company, ['billing_address' => "Same 1\r\nSame 2", 'tax_id' => 'SAME']))
        ->assertOk()->assertJsonPath('data.version', 1);

    expect(auditLogRows())->toBe([]);
});

it('never changes an issued invoice, its PDF or its list entry when the company is renamed or its address edited', function () {
    $w = orderWorld();
    $order = orderWith($w->a, [[1000, 2]], 'confirmed');
    $invoice = issueInvoiceAs($w->staff, $order)->assertCreated()->json('data');
    $before = [
        'detail' => $this->actingAs($w->a1)->getJson("/api/v1/invoices/{$invoice['id']}")->json('data'),
        'list' => $this->getJson('/api/v1/invoices')->json('data.0.company'),
        'row' => DB::table('invoices')->where('public_id', $invoice['id'])->first(),
    ];

    $this->actingAs($w->admin)->putJson("/api/v1/admin/companies/{$w->a->id}", adminBilling($w->a, ['name' => 'Completely New Name', 'billing_address' => "Elsewhere 1\nElsewhere 2", 'tax_id' => 'CHANGED']))->assertOk();

    expect($this->actingAs($w->a1)->getJson("/api/v1/invoices/{$invoice['id']}")->json('data'))->toEqual($before['detail'])
        ->and($this->getJson('/api/v1/invoices')->json('data.0.company'))->toEqual(['id' => $w->a->id, 'name' => 'Northwind Test Ltd'])
        ->and(DB::table('invoices')->where('public_id', $invoice['id'])->first())->toEqual($before['row'])
        ->and($before['detail']['buyer']['name'])->toBe('Northwind Test Ltd');
    $this->get("/api/v1/invoices/{$invoice['id']}/pdf")->assertOk();
});

it('has no delete or deactivate route for companies', function () {
    $company = Company::factory()->create();
    $this->actingAs($this->admin);

    $this->deleteJson("/api/v1/admin/companies/{$company->id}")->assertStatus(405);
    $this->postJson("/api/v1/admin/companies/{$company->id}/deactivate")->assertNotFound();
    expect(Company::count())->toBe(1);
});

it('ignores forged fields on company writes', function () {
    $company = Company::factory()->create(['name' => 'Forge Ltd']);

    $this->actingAs($this->admin)->putJson("/api/v1/admin/companies/{$company->id}", adminBilling($company, ['name' => 'Ok Ltd', 'id' => 99999, 'created_at' => '2000-01-01', 'active' => false, 'version_bump' => 5]))->assertOk();

    expect($company->fresh()->id)->toBe($company->id)->and($company->fresh()->created_at->year)->not->toBe(2000);
});

it('has the unguarded-company safeguard: nothing is mass assignable', function () {
    expect((new Company)->getFillable())->toBe([]);
});
