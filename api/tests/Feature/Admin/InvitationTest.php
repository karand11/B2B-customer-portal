<?php

use App\Mail\UserInvitationMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

const INVITATION_GENERIC = 'This invitation link is invalid or has expired.';

beforeEach(function () {
    config(['auth.passwords.invitations.expire' => 10080]);
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-11-27 12:00:00', 'UTC'));
    $this->admin = makeUser('admin');
    $this->company = Company::factory()->create(['name' => 'Acme Ltd']);
});

afterEach(fn () => $this->travelBack());

/** @return array{string, string} the token and email of the invitation link in the most recently queued mail */
function userInviteLink(): array
{
    $mail = Mail::queued(UserInvitationMail::class)->last();
    expect($mail)->not->toBeNull();
    parse_str((string) parse_url($mail->url, PHP_URL_QUERY), $query);

    return [$query['token'], $query['email']];
}

function userInvite(array $overrides = [], ?User $as = null)
{
    $body = array_replace(['name' => 'Nina Newhire', 'email' => 'nina@acme.test', 'role' => 'customer', 'company_id' => test()->company->id], $overrides);

    return test()->actingAs($as ?? test()->admin)->postJson('/api/v1/admin/users', $body);
}

function userInviteAccept(string $token, string $email, array $overrides = [], bool $freshLimiter = true)
{
    $password = 'Fresh-Passw0rd-1';
    $freshLimiter && Cache::flush(); // the 5-a-minute acceptance limiter is only under test in its own test

    return test()->postJson('/api/v1/auth/accept-invitation', array_replace(['token' => $token, 'email' => $email, 'password' => $password, 'password_confirmation' => $password], $overrides));
}

it('invites a customer: creates a pending user, queues one encrypted mail with a link built from config, and audits it', function () {
    $response = userInvite(['email' => '  Nina@Acme.TEST ', 'role' => 'customer'])->assertCreated();

    expect(array_keys($response->json('data')))->toBe(ADMIN_USER_KEYS)->and($response->json('meta'))->toBe(['email_queued' => true])
        ->and($response->json('data'))->toMatchArray([
            'name' => 'Nina Newhire', 'email' => 'nina@acme.test', 'role' => 'customer', 'status' => 'pending', 'invited_at' => '2026-11-27T12:00:00Z',
            'invitation_accepted_at' => null, 'company' => ['id' => $this->company->id, 'name' => 'Acme Ltd'], 'allowed_actions' => ['edit', 'assign', 'resend_invitation', 'revoke_invitation'],
        ]);

    $user = User::where('email', 'nina@acme.test')->firstOrFail();
    expect($user->active)->toBeTrue()->and($user->invited_by_user_id)->toBe($this->admin->id)->and($user->invitation_accepted_at)->toBeNull()->and($user->session_epoch)->toBe(1)
        ->and($user->company_id)->toBe($this->company->id)->and($user->primaryRole())->toBe('customer');

    Mail::assertQueued(UserInvitationMail::class, 1);
    $mail = Mail::queued(UserInvitationMail::class)->first();
    [$token, $email] = userInviteLink();
    expect($mail->hasTo('nina@acme.test'))->toBeTrue()->and($mail->url)->toStartWith(config('app.web_url').'/accept-invitation?token=')->and($email)->toBe('nina@acme.test')
        ->and(strlen($token))->toBeGreaterThan(30)->and($mail instanceof ShouldBeEncrypted)->toBeTrue()
        ->and($mail->render())->toContain('Set your password')->and($mail->validDays)->toBe(7);

    auditLogExpect(auditLogOne('user.invited'), $this->admin, 'user', (string) $user->id, $this->company->id, [
        'before' => [], 'after' => ['name' => 'Nina Newhire', 'role' => 'customer', 'company_id' => $this->company->id, 'active' => true],
    ]);
    expect(json_encode(auditLogRows()))->not->toContain($token);
});

it('invites staff and admins with the company key absent or null', function (string $role) {
    $body = ['name' => 'Sam', 'email' => "{$role}@portal.test", 'role' => $role];

    $this->actingAs($this->admin)->postJson('/api/v1/admin/users', $body)->assertCreated()->assertJsonPath('data.role', $role)->assertJsonPath('data.company', null);
    $this->postJson('/api/v1/admin/users', ['email' => "{$role}2@portal.test", 'company_id' => null] + $body)->assertCreated();
})->with(['staff', 'admin']);

it('validates the invitation', function (array $override, string $field) {
    userInvite($override)->assertStatus(422)->assertJsonValidationErrors($field);

    expect(User::where('email', 'nina@acme.test')->exists())->toBeFalse();
    Mail::assertNothingQueued();
    expect(auditLogRows())->toBe([]);
})->with([
    'name empty' => [['name' => ''], 'name'],
    'name 256' => [['name' => str_repeat('n', 256)], 'name'],
    'name control' => [['name' => "a\x07"], 'name'],
    'email missing' => [['email' => null], 'email'],
    'email invalid' => [['email' => 'not-an-email'], 'email'],
    'email 256' => [['email' => str_repeat('a', 250).'@x.test'], 'email'],
    'role missing' => [['role' => null], 'role'],
    'role unknown' => [['role' => 'owner'], 'role'],
    'customer without company' => [['company_id' => null], 'company_id'],
    'customer unknown company' => [['company_id' => 999999], 'company_id'],
    'customer text company' => [['company_id' => 'abc'], 'company_id'],
    'staff with company' => [['role' => 'staff'], 'company_id'],
    'admin with company' => [['role' => 'admin'], 'company_id'],
]);

it('refuses an email that is already used, in any case, by an active, pending, revoked or inactive user', function (string $state) {
    $existing = match ($state) {
        'active' => makeUser('staff', null, ['email' => 'taken@acme.test']),
        'pending' => makeUser('customer', $this->company, ['email' => 'taken@acme.test', 'invited_at' => now(), 'invitation_accepted_at' => null]),
        'revoked' => makeUser('customer', $this->company, ['email' => 'taken@acme.test', 'invited_at' => now(), 'active' => false]),
        'inactive' => makeUser('staff', null, ['email' => 'taken@acme.test', 'active' => false]),
    };

    userInvite(['email' => 'TAKEN@Acme.Test'])->assertStatus(422)->assertJsonPath('errors.email.0', 'This email is already in use.');

    expect(User::count())->toBe(2)->and($existing->fresh()->invited_by_user_id)->toBeNull();
})->with(['active', 'pending', 'revoked', 'inactive']);

it('builds the link from the config even when the request carries a hostile Host header', function () {
    $this->actingAs($this->admin)->withHeaders(['Host' => 'evil.test', 'X-Forwarded-Host' => 'evil.test', 'Origin' => 'http://localhost:5173'])
        ->postJson('/api/v1/admin/users', ['name' => 'N', 'email' => 'nina@acme.test', 'role' => 'staff'])->assertCreated();

    $mail = Mail::queued(UserInvitationMail::class)->first();
    expect($mail->url)->toStartWith(config('app.web_url').'/accept-invitation?token=')->not->toContain('evil.test');
});

it('still creates the invitation when the mail cannot be queued, and says so', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('queue is down'));

    $response = userInvite()->assertCreated();

    expect($response->json('meta'))->toBe(['email_queued' => false])->and(User::where('email', 'nina@acme.test')->exists())->toBeTrue();
});

it('limits invitations to 20 an hour per admin, every attempt counting', function () {
    foreach (range(1, 20) as $i) {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/users', [])->assertStatus(422);
    }

    $this->postJson('/api/v1/admin/users', [])->assertStatus(429)->assertHeader('Retry-After');
});

it('accepts an invitation once: sets the password and verified time, does not sign in, audits with the user as actor', function () {
    userInvite()->assertCreated();
    [$token, $email] = userInviteLink();
    $before = User::where('email', $email)->first();

    userInviteAccept($token, $email)->assertOk()->assertExactJson(['message' => 'Your account is ready. You can sign in now.']);

    $user = User::with('roles')->where('email', $email)->first();
    expect($user->status())->toBe('active')->and($user->invitation_accepted_at->toDateTimeString())->toBe('2026-11-27 12:00:00')->and($user->email_verified_at->toDateTimeString())->toBe('2026-11-27 12:00:00')
        ->and($user->session_epoch)->toBe($before->session_epoch + 1)->and(Hash::check('Fresh-Passw0rd-1', $user->password))->toBeTrue()
        ->and(DB::table('user_invitation_tokens')->where('email', $email)->count())->toBe(0);
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/me')->assertUnauthorized();

    auditLogExpect(auditLogOne('user.invitation_accepted'), $user, 'user', (string) $user->id, $this->company->id, []);
    expect(auditLogOne('user.invitation_accepted')['actor_role'])->toBe('customer');

    $login = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Fresh-Passw0rd-1'])->assertOk();
    expect($login->json('data.email'))->toBe($email);
});

it('answers every failed acceptance with the identical 422', function () {
    userInvite(['email' => 'a@acme.test'])->assertCreated();
    [$goodToken] = userInviteLink();
    $generic = ['message' => INVITATION_GENERIC, 'errors' => ['email' => [INVITATION_GENERIC]]];

    $unknown = userInviteAccept('definitely-not-a-token', 'nobody@acme.test')->assertStatus(422);
    expect($unknown->json())->toBe($generic);

    $bodies = [
        'wrong token' => userInviteAccept('wrong-token-value', 'a@acme.test'),
        'wrong email' => userInviteAccept($goodToken, 'b@acme.test'),
        'unknown email' => userInviteAccept($goodToken, 'ghost@acme.test'),
    ];

    // Used: accept once, then again.
    userInviteAccept($goodToken, 'a@acme.test')->assertOk();
    $bodies['used'] = userInviteAccept($goodToken, 'a@acme.test');

    // Revoked.
    userInvite(['email' => 'r@acme.test'])->assertCreated();
    [$revokedToken] = userInviteLink();
    $revoked = User::where('email', 'r@acme.test')->first();
    $this->actingAs($this->admin)->deleteJson("/api/v1/admin/users/{$revoked->id}/invitation")->assertOk();
    $bodies['revoked'] = userInviteAccept($revokedToken, 'r@acme.test');

    // Deactivated behind the service (the token still exists): the callback refuses a user who is not pending.
    userInvite(['email' => 'd@acme.test'])->assertCreated();
    [$deactivatedToken] = userInviteLink();
    User::where('email', 'd@acme.test')->update(['active' => false]);
    $bodies['deactivated'] = userInviteAccept($deactivatedToken, 'd@acme.test');

    // A non-pending user (an old active user) with a stray token.
    $active = makeUser('staff', null, ['email' => 'old@acme.test']);
    $stray = Password::broker('invitations')->createToken($active);
    $bodies['not pending'] = userInviteAccept($stray, 'old@acme.test');

    foreach ($bodies as $case => $response) {
        expect($response->getStatusCode())->toBe(422, $case)->and($response->json())->toBe($generic, $case)->and($response->getContent())->toBe($unknown->getContent(), $case);
    }
    expect(User::where('email', 'old@acme.test')->first()->invitation_accepted_at)->toBeNull();
});

it('expires an invitation after INVITATION_EXPIRE_MINUTES', function () {
    expect(config('auth.passwords.invitations.expire'))->toBe(10080);

    userInvite(['email' => 'early@acme.test'])->assertCreated();
    [$early] = userInviteLink();
    userInvite(['email' => 'late@acme.test'])->assertCreated();
    [$late] = userInviteLink();

    $this->travelTo(now()->addMinutes(10081));
    userInviteAccept($late, 'late@acme.test')->assertStatus(422)->assertJsonPath('errors.email.0', INVITATION_GENERIC);

    $this->travelTo(Carbon::parse('2026-11-27 12:00:00', 'UTC')->addMinutes(10079));
    userInviteAccept($early, 'early@acme.test')->assertOk();
});

it('applies the password rules on acceptance', function (array $override, string $field) {
    userInvite()->assertCreated();
    [$token, $email] = userInviteLink();

    userInviteAccept($token, $email, $override)->assertStatus(422)->assertJsonValidationErrors($field);

    expect(User::where('email', $email)->first()->status())->toBe('pending');
})->with([
    'too short' => [['password' => 'Short-1', 'password_confirmation' => 'Short-1'], 'password'],
    'not confirmed' => [['password_confirmation' => 'Different-Passw0rd'], 'password'],
    'too long' => [['password' => str_repeat('a', 256), 'password_confirmation' => str_repeat('a', 256)], 'password'],
    'token missing' => [['token' => null], 'token'],
    'email invalid' => [['email' => 'nope'], 'email'],
]);

it('does not let a reset token act as an invitation token or the other way round', function () {
    userInvite(['email' => 'cross@acme.test'])->assertCreated();
    [$invitationToken] = userInviteLink();
    $invited = User::where('email', 'cross@acme.test')->first();

    $active = makeUser('customer', $this->company, ['email' => 'active@acme.test']);
    $resetToken = Password::broker('users')->createToken($active);

    userInviteAccept($resetToken, 'active@acme.test')->assertStatus(422);
    $this->postJson('/api/v1/auth/reset-password', ['token' => $invitationToken, 'email' => 'cross@acme.test', 'password' => 'Other-Passw0rd-1', 'password_confirmation' => 'Other-Passw0rd-1'])->assertStatus(422);

    expect($invited->fresh()->status())->toBe('pending')->and(Hash::check(TEST_PASSWORD, $active->fresh()->password))->toBeTrue();
});

it('limits acceptance attempts to 5 a minute per client address', function () {
    foreach (range(1, 5) as $i) {
        userInviteAccept('x', 'x@acme.test', [], false)->assertStatus(422);
    }

    userInviteAccept('x', 'x@acme.test', [], false)->assertStatus(429);
});

it('resends: a new token replaces the old one, the mail is queued again, one throttled minute', function () {
    userInvite()->assertCreated();
    [$old, $email] = userInviteLink();
    $user = User::where('email', $email)->first();
    $this->actingAs($this->admin);

    $this->postJson("/api/v1/admin/users/{$user->id}/invitation")->assertStatus(422)->assertJsonValidationErrors('user');

    $this->travelTo(now()->addSeconds(61));
    $resent = $this->postJson("/api/v1/admin/users/{$user->id}/invitation")->assertOk();
    [$new] = userInviteLink();
    expect($resent->json('meta'))->toBe(['email_queued' => true])->and($resent->json('data.invited_at'))->toBe('2026-11-27T12:01:01Z')->and($new)->not->toBe($old);
    Mail::assertQueued(UserInvitationMail::class, 2);

    userInviteAccept($old, $email)->assertStatus(422);
    userInviteAccept($new, $email)->assertOk();
    auditLogExpect(auditLogOne('user.invitation_resent'), $this->admin, 'user', (string) $user->id, $this->company->id, []);
});

it('re-invites a revoked user with resend and refuses users who have no open invitation', function () {
    userInvite()->assertCreated();
    [$token, $email] = userInviteLink();
    $user = User::where('email', $email)->first();
    $accepted = makeUser('staff');
    $this->actingAs($this->admin);

    $this->deleteJson("/api/v1/admin/users/{$user->id}/invitation")->assertOk()->assertJsonPath('data.status', 'inactive');
    $this->postJson("/api/v1/admin/users/{$user->id}/invitation")->assertOk()->assertJsonPath('data.status', 'pending');
    [$fresh] = userInviteLink();
    userInviteAccept($token, $email)->assertStatus(422);
    userInviteAccept($fresh, $email)->assertOk();

    $this->postJson("/api/v1/admin/users/{$accepted->id}/invitation")->assertStatus(422)->assertJsonValidationErrors('user');
    $this->deleteJson("/api/v1/admin/users/{$accepted->id}/invitation")->assertStatus(422)->assertJsonValidationErrors('user');
    $this->postJson("/api/v1/admin/users/{$user->id}/invitation")->assertStatus(422);
});

it('revokes: the token and the login stop working, the epoch moves, the row and email stay', function () {
    userInvite()->assertCreated();
    [$token, $email] = userInviteLink();
    $user = User::where('email', $email)->first();

    $this->actingAs($this->admin)->deleteJson("/api/v1/admin/users/{$user->id}/invitation")->assertOk()
        ->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.allowed_actions', ['edit', 'assign', 'resend_invitation']);

    expect(User::find($user->id)->active)->toBeFalse()->and(User::find($user->id)->session_epoch)->toBe($user->session_epoch + 1)
        ->and(DB::table('user_invitation_tokens')->where('email', $email)->count())->toBe(0);
    userInviteAccept($token, $email)->assertStatus(422);
    auditLogExpect(auditLogOne('user.invitation_revoked'), $this->admin, 'user', (string) $user->id, $this->company->id, ['before' => ['active' => true], 'after' => ['active' => false]]);

    // Revoking again is a no-op.
    $this->actingAs($this->admin)->deleteJson("/api/v1/admin/users/{$user->id}/invitation")->assertOk();
    expect(auditLogRows('user.invitation_revoked'))->toHaveCount(1);
    userInvite(['email' => $email])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('keeps the invitation token out of the queue payload by encrypting the job', function () {
    // Back to the real mailer (Mail::fake() replaced the container binding) so the job really goes to the database queue.
    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstance('mail.manager');
    config(['queue.default' => 'database']);

    userInvite()->assertCreated()->assertJsonPath('meta.email_queued', true);

    $payload = DB::table('jobs')->value('payload');
    expect($payload)->not->toBeNull()->and($payload)->not->toContain('accept-invitation')->not->toContain('token=');
    $command = json_decode($payload, true)['data']['command'];
    $job = unserialize(decrypt($command));
    $mailable = $job->mailable;
    expect($job->shouldBeEncrypted)->toBeTrue()->and($mailable)->toBeInstanceOf(UserInvitationMail::class)->and($mailable->url)->toContain('/accept-invitation?token=');
    $token = explode('&', explode('token=', $mailable->url)[1])[0];
    expect(User::where('email', 'nina@acme.test')->exists())->toBeTrue()->and(Password::broker('invitations')->tokenExists(User::where('email', 'nina@acme.test')->first(), $token))->toBeTrue();
});

it('sends no reset notification to a freshly invited, still pending user', function () {
    Notification::fake();
    userInvite()->assertCreated();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nina@acme.test'])->assertOk();

    Notification::assertNothingSent();
});
