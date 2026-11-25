<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\DemoSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

const GENERIC_FORGOT = 'If an account exists for that email, a reset link has been sent.';
const INVALID_LINK = 'This password reset link is invalid or has expired.';
const NEW_PASSWORD = 'New-Passw0rd-123';

beforeEach(function () {
    $this->seed(DemoSeeder::class);
    $this->alice = User::where('email', 'alice@northwind.test')->first();
});

function requestResetToken(User $user): string
{
    Notification::fake();
    test()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

    return Notification::sent($user, ResetPasswordNotification::class)->first()->token;
}

function resetBody(string $token, array $override = []): array
{
    return array_merge([
        'token' => $token,
        'email' => 'alice@northwind.test',
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ], $override);
}

it('sends a queued reset notification linking to the SPA, not the API', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'alice@northwind.test'])
        ->assertOk()->assertExactJson(['message' => GENERIC_FORGOT]);

    Notification::assertSentTo($this->alice, ResetPasswordNotification::class, function ($n) {
        $url = $n->toMail($this->alice)->actionUrl;

        expect($url)->toStartWith('http://localhost:5173/reset-password?token=')
            ->and($url)->toContain('email=alice%40northwind.test')
            ->and($url)->not->toContain('localhost:8000');

        return true;
    });
    expect(new ResetPasswordNotification('t'))->toBeInstanceOf(ShouldQueue::class);
});

it('delivers a real email through the mailer', function () {
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'alice@northwind.test'])->assertOk();

    $messages = app('mailer')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $body = $messages[0]->getOriginalMessage()->toString();
    expect($body)->toContain('http://localhost:5173/reset-password?token=')->toContain('alice%40northwind.test');
});

it('lets a reset token work exactly once', function () {
    $token = requestResetToken($this->alice);

    $this->postJson('/api/v1/auth/reset-password', resetBody($token))
        ->assertOk()->assertExactJson(['message' => 'Your password has been reset.']);

    $this->postJson('/api/v1/auth/login', ['email' => 'alice@northwind.test', 'password' => TEST_PASSWORD])->assertStatus(422);
    $this->postJson('/api/v1/auth/login', ['email' => 'alice@northwind.test', 'password' => NEW_PASSWORD])->assertOk();

    $this->postJson('/api/v1/auth/reset-password', resetBody($token))
        ->assertStatus(422)
        ->assertExactJson(['message' => INVALID_LINK, 'errors' => ['email' => [INVALID_LINK]]]);
});

it('does not log the user in after reset', function () {
    $token = requestResetToken($this->alice);
    $this->postJson('/api/v1/auth/reset-password', resetBody($token))->assertOk();

    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('answers unknown emails identically and sends nothing', function () {
    Notification::fake();

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'alice@northwind.test']);
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@nowhere.test']);

    expect($unknown->status())->toBe($known->status())->and($unknown->json())->toBe($known->json());
    Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    Notification::assertNotSentTo(User::where('email', 'bob@northwind.test')->first(), ResetPasswordNotification::class);
});

it('validates forgot-password input', function (array $body) {
    $this->postJson('/api/v1/auth/forgot-password', $body)->assertStatus(422)->assertJsonValidationErrors('email');
})->with([
    'missing' => [[]],
    'malformed' => [['email' => 'nope']],
    'array' => [['email' => ['a@b.test']]],
]);

it('rejects expired, wrong, mismatched and tampered tokens with the same body', function () {
    $token = requestResetToken($this->alice);

    $bad = [
        'wrong token' => resetBody('wrong-token'),
        'wrong email' => resetBody($token, ['email' => 'bob@northwind.test']),
        'unknown email' => resetBody($token, ['email' => 'ghost@nowhere.test']),
        'tampered token' => resetBody($token.'x'),
    ];
    foreach ($bad as $body) {
        $this->postJson('/api/v1/auth/reset-password', $body)
            ->assertStatus(422)
            ->assertExactJson(['message' => INVALID_LINK, 'errors' => ['email' => [INVALID_LINK]]]);
    }

    $this->travel(61)->minutes();
    $this->postJson('/api/v1/auth/reset-password', resetBody($token))
        ->assertStatus(422)
        ->assertExactJson(['message' => INVALID_LINK, 'errors' => ['email' => [INVALID_LINK]]]);
});

it('validates the new password', function (array $override) {
    $token = requestResetToken($this->alice);

    $this->postJson('/api/v1/auth/reset-password', resetBody($token, $override))
        ->assertStatus(422)->assertJsonValidationErrors('password');
})->with([
    'too short' => [['password' => 'short', 'password_confirmation' => 'short']],
    'mismatch' => [['password_confirmation' => 'Different-Passw0rd']],
    'missing confirmation' => [['password_confirmation' => null]],
]);

it('does not reveal the broker throttle: a second request within 60s is a silent 200', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'alice@northwind.test'])->assertOk();
    $second = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'alice@northwind.test'])
        ->assertOk()->assertExactJson(['message' => GENERIC_FORGOT]);

    Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    expect($second->status())->toBe(200);
});

it('rate limits forgot-password and reset-password to 5 per minute per IP', function (string $uri, array $body) {
    foreach (range(1, 5) as $i) {
        expect($this->postJson($uri, $body)->status())->not->toBe(429);
    }

    $this->postJson($uri, $body)->assertStatus(429)->assertHeader('Retry-After');
})->with([
    'forgot' => ['/api/v1/auth/forgot-password', ['email' => 'ghost@nowhere.test']],
    'reset' => ['/api/v1/auth/reset-password', resetBody('whatever')],
]);

it('ignores role and company_id on forgot and reset bodies', function () {
    $token = requestResetToken($this->alice);
    $companyId = $this->alice->company_id;

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'alice@northwind.test', 'role' => 'admin', 'company_id' => 999])->assertOk();
    $this->postJson('/api/v1/auth/reset-password', resetBody($token, ['role' => 'admin', 'company_id' => 999]))->assertOk();

    $fresh = $this->alice->fresh();
    expect($fresh->company_id)->toBe($companyId)->and($fresh->getRoleNames()->all())->toBe(['customer']);
});

it('invalidates other sessions of the user after a password reset', function () {
    $login = $this->postJson('/api/v1/auth/login', ['email' => 'alice@northwind.test', 'password' => TEST_PASSWORD])->assertOk();
    carrySession($login);
    $this->getJson('/api/v1/me')->assertOk();

    $token = requestResetToken($this->alice);
    $this->postJson('/api/v1/auth/reset-password', resetBody($token))->assertOk();

    // Drop the guard's cached user so only the old session cookie counts.
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});
