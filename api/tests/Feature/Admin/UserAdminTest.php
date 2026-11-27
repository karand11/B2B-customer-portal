<?php

use App\Models\CartItem;
use App\Models\Company;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\UserAdmin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-27 12:00:00', 'UTC'));
    $this->admin = makeUser('admin', null, ['name' => 'Ada Admin']);
    $this->company = Company::factory()->create(['name' => 'Acme Ltd']);
});

afterEach(fn () => $this->travelBack());

/** A pending (invited, never accepted) user; $revoked makes it inactive like a revoked invitation. */
function userAdminPending(string $role = 'customer', ?Company $company = null, bool $revoked = false): User
{
    return makeUser($role, $company, ['invited_at' => now()->subDay(), 'invitation_accepted_at' => null, 'active' => ! $revoked]);
}

/** Sign in through the real endpoint and keep the session cookie for the next requests. */
function userAdminLogin(User $user): void
{
    carrySession(test()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TEST_PASSWORD])->assertOk());
}

/** A request that only the session cookie authenticates (the test app caches the resolved user on the guard otherwise). */
function userAdminSessionGet(string $uri = '/api/v1/me')
{
    app('auth')->forgetGuards();

    return test()->getJson($uri);
}

it('lists users with the contract keys, filters, sorts and pagination, and never a secret', function () {
    $customer = makeUser('customer', $this->company, ['name' => 'Cara Customer', 'email' => 'cara@acme.test']);
    $staff = makeUser('staff', null, ['name' => 'Sam Staff', 'email' => 'sam@portal.test']);
    $inactive = makeUser('customer', $this->company, ['name' => 'Ina Inactive', 'email' => 'ina@acme.test', 'active' => false]);
    $pending = userAdminPending('customer', $this->company);
    $pending->forceFill(['name' => 'Pat Pending', 'email' => 'pat@acme.test'])->save();
    $this->actingAs($this->admin);
    $names = fn (string $q = '') => array_column($this->getJson('/api/v1/admin/users'.$q)->assertOk()->json('data'), 'name');

    $response = $this->getJson('/api/v1/admin/users')->assertOk();
    expect(array_keys($response->json('data.0')))->toBe(ADMIN_USER_KEYS)->and(array_keys($response->json('meta')))->toBe(['current_page', 'last_page', 'per_page', 'total', 'from', 'to'])
        ->and(strtolower($response->getContent()))->not->toContain('"password"')->not->toContain('remember_token')->not->toContain('session_epoch')->not->toContain('"token"');

    expect($names('?role=staff'))->toBe(['Sam Staff'])
        ->and($names('?role=customer,staff&sort=name'))->toBe(['Cara Customer', 'Ina Inactive', 'Pat Pending', 'Sam Staff'])
        ->and($names('?status=inactive'))->toBe(['Ina Inactive'])
        ->and($names('?status=pending'))->toBe(['Pat Pending'])
        ->and($names('?status=active&sort=name'))->toBe(['Ada Admin', 'Cara Customer', 'Sam Staff'])
        ->and($names('?status=inactive,pending&sort=-name'))->toBe(['Pat Pending', 'Ina Inactive'])
        ->and($names("?company_id={$this->company->id}&sort=email"))->toBe(['Cara Customer', 'Ina Inactive', 'Pat Pending'])
        ->and($names('?q=ACME&sort=-email'))->toBe(['Pat Pending', 'Ina Inactive', 'Cara Customer'])
        ->and($names('?q=sam'))->toBe(['Sam Staff'])
        ->and($names('?per_page=2&page=2&sort=email'))->toHaveCount(2)
        ->and($names('?page=99'))->toBe([]);
});

it('treats % and _ in q as literal characters', function () {
    makeUser('staff', null, ['name' => '100% Real', 'email' => 'real@portal.test']);
    makeUser('staff', null, ['name' => 'Under_score', 'email' => 'under@portal.test']);
    $this->actingAs($this->admin);
    $names = fn (string $q) => array_column($this->getJson("/api/v1/admin/users?q={$q}")->assertOk()->json('data'), 'name');

    expect($names('%25'))->toBe(['100% Real'])->and($names('_'))->toBe(['Under_score'])->and($names('x_s'))->toBe([]);
});

it('refuses bad list parameters on the parameter name', function () {
    $this->actingAs($this->admin);

    foreach (['sort=bogus', 'sort=-company', 'role=boss', 'role=admin,,staff', 'status=gone', 'company_id=x', 'company_id=0', 'per_page=101', 'page=0', 'q[]=a', 'role[]=admin', 'status[]=active'] as $bad) {
        $this->getJson("/api/v1/admin/users?{$bad}")->assertStatus(422)->assertJsonValidationErrors(explode('=', explode('[', $bad)[0])[0]);
    }
});

it('lists with a constant number of queries', function () {
    $this->actingAs($this->admin);
    $count = fn () => adminQueryCount(fn () => $this->getJson('/api/v1/admin/users?per_page=100')->assertOk());
    $count(); // warm-up
    makeUser('customer', $this->company);
    $few = $count();
    foreach (range(1, 20) as $i) {
        makeUser($i % 2 ? 'customer' : 'staff', $i % 2 ? Company::factory()->create() : null);
    }

    expect($count())->toBe($few)->and($few)->toBeLessThanOrEqual(4); // count, rows, roles, companies
});

it('shows one user in 3 queries and 404s a missing one', function () {
    $user = makeUser('customer', $this->company);

    $this->actingAs($this->admin);
    $request = fn () => $this->getJson("/api/v1/admin/users/{$user->id}")->assertOk()->assertJsonPath('data.company', ['id' => $this->company->id, 'name' => 'Acme Ltd']);
    $request(); // warm-up

    expect(adminQueryCount($request))->toBeLessThanOrEqual(3);
    $this->getJson('/api/v1/admin/users/999999')->assertNotFound()->assertExactJson(NOT_FOUND);
});

it('computes allowed actions for the calling admin and the row', function () {
    $active = makeUser('customer', $this->company);
    $inactive = makeUser('customer', $this->company, ['active' => false]);
    $pending = userAdminPending('customer', $this->company);
    $revoked = userAdminPending('customer', $this->company, true);
    $this->actingAs($this->admin);
    $actions = fn (User $u) => $this->getJson("/api/v1/admin/users/{$u->id}")->assertOk()->json('data.allowed_actions');

    expect($actions($this->admin))->toBe(['edit'])
        ->and($actions($active))->toBe(['edit', 'assign', 'deactivate', 'reset_password'])
        ->and($actions($inactive))->toBe(['edit', 'assign', 'reactivate'])
        ->and($actions($pending))->toBe(['edit', 'assign', 'resend_invitation', 'revoke_invitation'])
        ->and($actions($revoked))->toBe(['edit', 'assign', 'resend_invitation']);
});

it('derives the status: inactive beats pending beats active', function () {
    expect(makeUser('customer', $this->company)->status())->toBe('active')
        ->and(makeUser('customer', $this->company, ['active' => false])->status())->toBe('inactive')
        ->and(userAdminPending('customer', $this->company)->status())->toBe('pending')
        ->and(userAdminPending('customer', $this->company, true)->status())->toBe('inactive')
        ->and(makeUser('customer', $this->company, ['invited_at' => now(), 'invitation_accepted_at' => now()])->status())->toBe('active');
});

it('renames a user, audits it, and ignores the email and every forged field', function () {
    $user = makeUser('customer', $this->company, ['name' => 'Old Name', 'email' => 'keep@acme.test']);
    $epoch = $user->session_epoch;

    $this->actingAs($this->admin)->patchJson("/api/v1/admin/users/{$user->id}", [
        'name' => 'New Name', 'email' => 'new@evil.test', 'role' => 'admin', 'company_id' => null, 'active' => false, 'session_epoch' => 99, 'password' => 'Hacked-Passw0rd!', 'invited_by_user_id' => 1,
    ])->assertOk()->assertJsonPath('data.name', 'New Name')->assertJsonPath('data.email', 'keep@acme.test')->assertJsonPath('data.role', 'customer');

    $fresh = User::find($user->id);
    expect($fresh->email)->toBe('keep@acme.test')->and($fresh->company_id)->toBe($this->company->id)->and($fresh->active)->toBeTrue()->and($fresh->session_epoch)->toBe($epoch)
        ->and($fresh->primaryRole())->toBe('customer')->and(Hash::check(TEST_PASSWORD, $fresh->password))->toBeTrue()->and($fresh->invited_by_user_id)->toBeNull();
    auditLogExpect(auditLogOne('user.updated'), $this->admin, 'user', (string) $user->id, $this->company->id, ['before' => ['name' => 'Old Name'], 'after' => ['name' => 'New Name']]);
});

it('does not audit a rename to the same name and validates the name', function () {
    $user = makeUser('staff', null, ['name' => 'Same']);
    $this->actingAs($this->admin);

    $this->patchJson("/api/v1/admin/users/{$user->id}", ['name' => 'Same'])->assertOk();
    expect(auditLogRows())->toBe([]);

    foreach ([[''], [str_repeat('n', 256)], ["a\x07b"], [null], ["a\u{200B}b"]] as [$bad]) {
        $this->patchJson("/api/v1/admin/users/{$user->id}", ['name' => $bad])->assertStatus(422)->assertJsonValidationErrors('name');
    }
    $this->patchJson("/api/v1/admin/users/{$user->id}", [])->assertStatus(422)->assertJsonValidationErrors('name');
});

it('moves a customer to staff: clears the company and the cart, bumps the epoch, keeps the orders', function () {
    $w = orderWorld();
    $user = $w->a1;
    $order = orderWith($w->a, [[100, 1]], 'pending', ['created_by_user_id' => $user->id]);
    fillCart($user, [[makeProduct('CART-1'), 2]]);

    $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$user->id}/assignment", ['role' => 'staff', 'company_id' => null])->assertOk()
        ->assertJsonPath('data.role', 'staff')->assertJsonPath('data.company', null);

    $fresh = User::with('roles')->find($user->id);
    expect($fresh->roles->pluck('name')->all())->toBe(['staff'])->and($fresh->company_id)->toBeNull()->and($fresh->session_epoch)->toBe($user->session_epoch + 1)
        ->and(CartItem::where('user_id', $user->id)->count())->toBe(0)->and($order->fresh()->company_id)->toBe($w->a->id);
    auditLogExpect(auditLogOne('user.assignment_changed'), $this->admin, 'user', (string) $user->id, $w->a->id, [
        'before' => ['role' => 'customer', 'company_id' => $w->a->id], 'after' => ['role' => 'staff', 'company_id' => null],
    ]);
});

it('moves staff to customer only with an existing company, and customer to customer between companies', function () {
    $staff = makeUser('staff');
    $other = Company::factory()->create();
    $this->actingAs($this->admin);
    $url = "/api/v1/admin/users/{$staff->id}/assignment";

    $this->putJson($url, ['role' => 'customer', 'company_id' => null])->assertStatus(422)->assertJsonValidationErrors('company_id');
    $this->putJson($url, ['role' => 'customer', 'company_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('company_id');
    $this->putJson($url, ['role' => 'customer'])->assertStatus(422)->assertJsonValidationErrors('company_id');
    $this->putJson($url, ['role' => 'customer', 'company_id' => $this->company->id])->assertOk()->assertJsonPath('data.role', 'customer')->assertJsonPath('data.company.id', $this->company->id);
    $this->putJson($url, ['role' => 'customer', 'company_id' => $other->id])->assertOk()->assertJsonPath('data.company.id', $other->id);

    expect(User::find($staff->id)->company_id)->toBe($other->id)->and(auditLogRows('user.assignment_changed'))->toHaveCount(2);
});

it('refuses invalid role and company combinations', function (array $body, string $field) {
    $user = makeUser('customer', $this->company);

    $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$user->id}/assignment", $body)->assertStatus(422)->assertJsonValidationErrors($field);
    expect(User::find($user->id)->company_id)->toBe($this->company->id)->and(auditLogRows())->toBe([]);
})->with([
    'unknown role' => [['role' => 'root', 'company_id' => null], 'role'],
    'missing role' => [['company_id' => null], 'role'],
    'role array' => [['role' => ['admin'], 'company_id' => null], 'role'],
    'staff with company' => [['role' => 'staff', 'company_id' => 1], 'company_id'],
    'admin with company' => [['role' => 'admin', 'company_id' => 1], 'company_id'],
    'company text' => [['role' => 'customer', 'company_id' => 'abc'], 'company_id'],
    'company float' => [['role' => 'customer', 'company_id' => 1.5], 'company_id'],
    'company key missing' => [['role' => 'staff'], 'company_id'],
]);

it('is a no-op without audit row or epoch bump when role and company stay the same', function () {
    $user = makeUser('customer', $this->company);

    $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$user->id}/assignment", ['role' => 'customer', 'company_id' => $this->company->id])->assertOk();

    expect(User::find($user->id)->session_epoch)->toBe($user->session_epoch)->and(auditLogRows())->toBe([]);
});

it('refuses to change your own role or company, with the error on user', function () {
    $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$this->admin->id}/assignment", ['role' => 'staff', 'company_id' => null])->assertStatus(422)
        ->assertJsonValidationErrors('user')->assertJsonPath('errors.user.0', 'You cannot change your own role or company.');

    expect(User::find($this->admin->id)->primaryRole())->toBe('admin');
});

it('lets one admin demote another, then the remaining admin is protected', function () {
    $second = makeUser('admin');

    $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$second->id}/assignment", ['role' => 'staff', 'company_id' => null])->assertOk();

    // The second user is staff now: no HTTP path can remove the last admin (a staff user cannot call the endpoint, an admin cannot target themselves).
    $this->actingAs($second->fresh())->putJson("/api/v1/admin/users/{$this->admin->id}/assignment", ['role' => 'staff', 'company_id' => null])->assertForbidden();
    expect(User::find($this->admin->id)->primaryRole())->toBe('admin');
});

it('refuses, at the service level, to demote or deactivate the last active administrator', function () {
    $actor = makeUser('staff'); // a caller that is not in the admin set: only possible outside HTTP, which is what the guard is for
    $service = app(UserAdmin::class);

    expect(fn () => $service->assign($actor, $this->admin, 'staff', null))->toThrow(ValidationException::class, 'At least one active administrator is required.');
    expect(fn () => $service->deactivate($actor, $this->admin))->toThrow(ValidationException::class, 'At least one active administrator is required.');
    expect($this->admin->fresh()->active)->toBeTrue()->and($this->admin->fresh()->primaryRole())->toBe('admin')->and(auditLogRows())->toBe([]);
});

it('does not count pending or inactive administrators as administrators', function () {
    $actor = makeUser('staff');
    userAdminPending('admin');
    makeUser('admin', null, ['active' => false]);
    $service = app(UserAdmin::class);

    expect(fn () => $service->assign($actor, $this->admin, 'staff', null))->toThrow(ValidationException::class);

    $second = makeUser('admin'); // a second ACTIVE admin makes the demotion legal
    $service->assign($actor, $this->admin, 'staff', null);
    expect($this->admin->fresh()->primaryRole())->toBe('staff')->and($second->fresh()->primaryRole())->toBe('admin');
});

it('deactivates a user, bumps the epoch, deletes reset and invitation tokens and audits it', function () {
    $user = makeUser('customer', $this->company);
    Password::broker('users')->createToken($user);
    DB::table('user_invitation_tokens')->insert(['email' => $user->email, 'token' => 'x', 'created_at' => now()]);

    $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$user->id}/deactivate", ['active' => true])->assertOk()
        ->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.allowed_actions', ['edit', 'assign', 'reactivate']);

    expect(User::find($user->id)->session_epoch)->toBe($user->session_epoch + 1)
        ->and(DB::table('password_reset_tokens')->where('email', $user->email)->count())->toBe(0)
        ->and(DB::table('user_invitation_tokens')->where('email', $user->email)->count())->toBe(0);
    auditLogExpect(auditLogOne('user.deactivated'), $this->admin, 'user', (string) $user->id, $this->company->id, ['before' => ['active' => true], 'after' => ['active' => false]]);
});

it('is idempotent for deactivate and reactivate, and audits each real change once', function () {
    $user = makeUser('staff');
    $this->actingAs($this->admin);

    $this->postJson("/api/v1/admin/users/{$user->id}/reactivate")->assertOk(); // already active
    $this->postJson("/api/v1/admin/users/{$user->id}/deactivate")->assertOk();
    $epoch = User::find($user->id)->session_epoch;
    $this->postJson("/api/v1/admin/users/{$user->id}/deactivate")->assertOk();
    expect(User::find($user->id)->session_epoch)->toBe($epoch);
    $this->postJson("/api/v1/admin/users/{$user->id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson("/api/v1/admin/users/{$user->id}/reactivate")->assertOk();

    expect(auditLogRows('user.deactivated'))->toHaveCount(1)->and(auditLogRows('user.reactivated'))->toHaveCount(1)->and(User::find($user->id)->session_epoch)->toBe($epoch + 1);
    auditLogExpect(auditLogOne('user.reactivated'), $this->admin, 'user', (string) $user->id, null, ['before' => ['active' => false], 'after' => ['active' => true]]);
});

it('refuses to deactivate yourself or a pending user, and to reactivate a never-accepted one, with the error on user', function () {
    $pending = userAdminPending();
    $revoked = userAdminPending('customer', $this->company, true);
    $this->actingAs($this->admin);

    $this->postJson("/api/v1/admin/users/{$this->admin->id}/deactivate")->assertStatus(422)->assertJsonValidationErrors('user');
    $this->postJson("/api/v1/admin/users/{$pending->id}/deactivate")->assertStatus(422)->assertJsonValidationErrors('user');
    $this->postJson("/api/v1/admin/users/{$revoked->id}/reactivate")->assertStatus(422)->assertJsonValidationErrors('user');

    expect($this->admin->fresh()->active)->toBeTrue()->and(auditLogRows())->toBe([]);
});

it('ignores forged fields on every user action', function () {
    $user = makeUser('customer', $this->company);
    $forged = ['role' => 'admin', 'company_id' => null, 'active' => false, 'session_epoch' => 50, 'password' => 'Hacked-Passw0rd!', 'email' => 'x@evil.test', 'name' => 'Forged'];
    $this->actingAs($this->admin);

    $this->postJson("/api/v1/admin/users/{$user->id}/password-reset", $forged)->assertOk();
    $this->postJson("/api/v1/admin/users/{$user->id}/deactivate", $forged)->assertOk();
    $this->postJson("/api/v1/admin/users/{$user->id}/reactivate", $forged)->assertOk();

    $fresh = User::with('roles')->find($user->id);
    expect($fresh->primaryRole())->toBe('customer')->and($fresh->company_id)->toBe($this->company->id)->and($fresh->active)->toBeTrue()->and($fresh->session_epoch)->toBe($user->session_epoch + 2)
        ->and($fresh->name)->not->toBe('Forged')->and($fresh->email)->not->toBe('x@evil.test');
});

it('kills the session of a deactivated user on the very next request', function () {
    $user = makeUser('customer', $this->company);
    userAdminLogin($user);
    userAdminSessionGet()->assertOk();

    app(UserAdmin::class)->deactivate($this->admin, $user);

    userAdminSessionGet()->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    userAdminSessionGet('/api/v1/products')->assertUnauthorized();
});

it('kills the sessions after a role or company change', function (string $change) {
    $user = makeUser('customer', $this->company);
    userAdminLogin($user);
    userAdminSessionGet()->assertOk();

    $change === 'staff'
        ? app(UserAdmin::class)->assign($this->admin, $user, 'staff', null)
        : app(UserAdmin::class)->assign($this->admin, $user, 'customer', Company::factory()->create()->id);

    userAdminSessionGet()->assertUnauthorized();
})->with(['staff', 'company']);

it('does not resurrect an old session on reactivation, but allows a new login', function () {
    $user = makeUser('customer', $this->company);
    userAdminLogin($user);
    app(UserAdmin::class)->deactivate($this->admin, $user);
    app(UserAdmin::class)->reactivate($this->admin, $user);

    userAdminSessionGet()->assertUnauthorized();

    userAdminLogin($user->fresh());
    userAdminSessionGet()->assertOk()->assertJsonPath('data.email', $user->email);
});

it('treats a session without a stored epoch as stale', function () {
    $user = makeUser('customer', $this->company);

    $this->actingAs($user)->withSession(['session_epoch' => null])->getJson('/api/v1/me')->assertUnauthorized();
});

it('answers a deactivated or pending user at login exactly like a wrong password, and counts the failure', function () {
    $inactive = makeUser('customer', $this->company, ['active' => false]);
    $pending = userAdminPending();
    $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@acme.test', 'password' => TEST_PASSWORD])->assertStatus(422);

    foreach ([$inactive, $pending] as $user) {
        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TEST_PASSWORD])->assertStatus(422);
        expect($response->json())->toBe($unknown->json());
    }

    // The first attempt above was the 1st failure for this email and IP: four more reach the limit of five, the next is refused.
    foreach (range(1, 4) as $i) {
        $this->postJson('/api/v1/auth/login', ['email' => $inactive->email, 'password' => TEST_PASSWORD])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/login', ['email' => $inactive->email, 'password' => TEST_PASSWORD])->assertStatus(429);
});

it('sends no reset email to deactivated or pending users but answers the same 200', function () {
    Notification::fake();
    $active = makeUser('customer', $this->company);
    $inactive = makeUser('customer', $this->company, ['active' => false]);
    $pending = userAdminPending();

    $bodies = collect([$active, $inactive, $pending])->map(fn (User $u) => $this->postJson('/api/v1/auth/forgot-password', ['email' => $u->email])->assertOk()->json());

    expect($bodies->unique()->count())->toBe(1);
    Notification::assertSentTo($active, ResetPasswordNotification::class);
    Notification::assertNotSentTo($inactive, ResetPasswordNotification::class);
    Notification::assertNotSentTo($pending, ResetPasswordNotification::class);
});

it('refuses reset-password with a token that predates deactivation or belongs to a user who cannot sign in', function () {
    $user = makeUser('customer', $this->company);
    $token = Password::broker('users')->createToken($user);
    app(UserAdmin::class)->deactivate($this->admin, $user);

    $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'New-Passw0rd-123', 'password_confirmation' => 'New-Passw0rd-123'])->assertStatus(422);

    // Even when a token still exists (inserted behind the service), the callback refuses the user.
    $token = Password::broker('users')->createToken($user);
    $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'New-Passw0rd-123', 'password_confirmation' => 'New-Passw0rd-123'])
        ->assertStatus(422)->assertJsonPath('errors.email.0', 'This password reset link is invalid or has expired.');
    expect(Hash::check(TEST_PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('queues a reset email for an active user, audits it, and throttles to one a minute', function () {
    Notification::fake();
    $user = makeUser('customer', $this->company);
    $this->actingAs($this->admin);

    $this->postJson("/api/v1/admin/users/{$user->id}/password-reset")->assertOk()->assertExactJson(['message' => 'A password reset email has been queued.']);
    Notification::assertSentTo($user, ResetPasswordNotification::class);
    auditLogExpect(auditLogOne('user.password_reset_requested'), $this->admin, 'user', (string) $user->id, $this->company->id, []);

    $this->postJson("/api/v1/admin/users/{$user->id}/password-reset")->assertStatus(422)->assertJsonValidationErrors('user');
    expect(auditLogRows('user.password_reset_requested'))->toHaveCount(1);

    $this->travelTo(now()->addSeconds(61));
    $this->postJson("/api/v1/admin/users/{$user->id}/password-reset")->assertOk();
    expect(Hash::check(TEST_PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('refuses a reset email for inactive and pending users', function () {
    Notification::fake();
    $this->actingAs($this->admin);

    foreach ([makeUser('staff', null, ['active' => false]), userAdminPending(), userAdminPending('customer', null, true)] as $user) {
        $this->postJson("/api/v1/admin/users/{$user->id}/password-reset")->assertStatus(422)->assertJsonValidationErrors('user');
    }
    Notification::assertNothingSent();
});

it('lets only admins call any user endpoint', function () {
    $user = makeUser('customer', $this->company);
    $calls = [
        ['getJson', '/api/v1/admin/users'], ['postJson', '/api/v1/admin/users'], ['getJson', "/api/v1/admin/users/{$user->id}"], ['patchJson', "/api/v1/admin/users/{$user->id}"],
        ['putJson', "/api/v1/admin/users/{$user->id}/assignment"], ['postJson', "/api/v1/admin/users/{$user->id}/deactivate"], ['postJson', "/api/v1/admin/users/{$user->id}/reactivate"],
        ['postJson', "/api/v1/admin/users/{$user->id}/password-reset"], ['postJson', "/api/v1/admin/users/{$user->id}/invitation"], ['deleteJson', "/api/v1/admin/users/{$user->id}/invitation"],
        ['getJson', '/api/v1/admin/audit-log'],
    ];

    foreach ([makeUser('customer', $this->company), makeUser('staff')] as $caller) {
        foreach ($calls as [$method, $uri]) {
            $this->actingAs($caller)->{$method}($uri, [])->assertForbidden();
        }
    }
    expect(User::find($user->id)->active)->toBeTrue()->and(auditLogRows())->toBe([]);
});

it('stops a staff user from giving themselves the admin role through any request', function () {
    $staff = makeUser('staff');
    $this->actingAs($staff);

    $this->putJson("/api/v1/admin/users/{$staff->id}/assignment", ['role' => 'admin', 'company_id' => null])->assertForbidden();
    $this->patchJson("/api/v1/admin/users/{$staff->id}", ['name' => 'Boss', 'role' => 'admin'])->assertForbidden();
    $this->patchJson('/api/v1/orders/ORD-AAAAA-AAAAA/status', ['status' => 'confirmed', 'role' => 'admin', 'company_id' => 1])->assertNotFound();

    expect(User::with('roles')->find($staff->id)->primaryRole())->toBe('staff');
});
