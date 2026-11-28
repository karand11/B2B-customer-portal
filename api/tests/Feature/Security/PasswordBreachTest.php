<?php

use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Support\FailOpenPwnedVerifier;
use App\Support\PasswordPolicy;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Tests\Support\LogCapture;

const SEC_PWNED = 'Passw0rd-Pwned-123';
const SEC_CLEAN = 'Unique-Clean-Phrase-77';

/** The range API answer for a password: its own suffix with a count, plus filler lines and a padded zero entry. */
function secPwnedBody(string $password, int $count): string
{
    $hash = strtoupper(sha1($password));

    return "0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n".substr($hash, 5).":{$count}\r\n00D4F6E8FA6EECAD2A3AA415EEC418D38EC:0\r\n";
}

beforeEach(function () {
    config(['portal.password_breach_check' => true]);
    $this->user = makeUser('customer');
    $this->invited = makeUser('customer', null, ['invited_at' => now()->subHour(), 'invitation_accepted_at' => null]);
});

function secResetBody(User $user, string $password): array
{
    return ['token' => Password::createToken($user), 'email' => $user->email, 'password' => $password, 'password_confirmation' => $password];
}

function secInviteBody(User $user, string $password): array
{
    return ['token' => Password::broker('invitations')->createToken($user), 'email' => $user->email, 'password' => $password, 'password_confirmation' => $password];
}

it('refuses a password found in a breach, on reset and on accepting an invitation, with 422 on `password`', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(secPwnedBody(SEC_PWNED, 5000))]);

    $reset = $this->postJson('/api/v1/auth/reset-password', secResetBody($this->user, SEC_PWNED))->assertStatus(422);
    Cache::flush();
    $accept = $this->postJson('/api/v1/auth/accept-invitation', secInviteBody($this->invited, SEC_PWNED))->assertStatus(422);

    expect(array_keys($reset->json('errors')))->toBe(['password'])->and(array_keys($accept->json('errors')))->toBe(['password'])
        ->and(Hash::check(SEC_PWNED, $this->user->fresh()->password))->toBeFalse();
});

it('accepts a clean password on both endpoints', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(secPwnedBody('Somebody-Elses-Pass-1', 9000))]);

    $this->postJson('/api/v1/auth/reset-password', secResetBody($this->user, SEC_CLEAN))->assertOk();
    Cache::flush();
    $this->postJson('/api/v1/auth/accept-invitation', secInviteBody($this->invited, SEC_CLEAN))->assertOk();
});

it('uses the threshold of 3: seen in at most three breaches passes, four is refused', function (int $count, int $status) {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(secPwnedBody(SEC_PWNED, $count))]);

    $this->postJson('/api/v1/auth/reset-password', secResetBody($this->user, SEC_PWNED))->assertStatus($status);
})->with([[0, 200], [3, 200], [4, 422]]);

it('sends only the first five characters of the SHA-1 and nothing that identifies the user', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(secPwnedBody(SEC_CLEAN, 0))]);
    $hash = strtoupper(sha1(SEC_CLEAN));

    $this->postJson('/api/v1/auth/reset-password', secResetBody($this->user, SEC_CLEAN))->assertOk();

    Http::assertSentCount(1);
    Http::assertSent(function (HttpRequest $request) use ($hash) {
        $everything = $request->url().json_encode($request->headers()).$request->body();

        return $request->url() === 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5)
            && $request->method() === 'GET'
            && ! str_contains($everything, substr($hash, 5, 10))
            && ! str_contains($everything, SEC_CLEAN)
            && ! str_contains($everything, $this->user->email)
            && ! str_contains(strtolower($everything), 'cookie')
            && ! str_contains(strtolower($everything), 'authorization');
    });
});

it('fails OPEN with a warning when the service is down, slow, or answers an error, and never logs the hash prefix, the password or the exception', function (Closure $fake) {
    $logs = LogCapture::start();
    Http::fake(['api.pwnedpasswords.com/*' => $fake]);

    $this->postJson('/api/v1/auth/reset-password', secResetBody($this->user, SEC_PWNED))->assertOk();

    $warnings = array_values(array_filter(LogCapture::records($logs), fn ($r) => $r['message'] === 'Password breach check unavailable'));
    expect($warnings)->toHaveCount(1)->and($warnings[0]['level'])->toBe('WARNING');
    $text = LogCapture::text($logs);
    expect($text)->not->toContain(SEC_PWNED)->not->toContain(substr(strtoupper(sha1(SEC_PWNED)), 0, 5))->not->toContain('pwnedpasswords');
})->with([
    'server error' => [fn () => Http::response('boom', 500)],
    'rate limited' => [fn () => Http::response('', 429)],
    'connection refused' => [fn () => throw new ConnectionException('cURL error 7: Failed to connect to api.pwnedpasswords.com/range/ABCDE')],
    'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out after 2001 milliseconds (https://api.pwnedpasswords.com/range/ABCDE)')],
]);

it('does not call the service at all when the check is off (the local and test default)', function () {
    config(['portal.password_breach_check' => false]);
    Http::fake();

    $this->postJson('/api/v1/auth/reset-password', secResetBody($this->user, SEC_PWNED))->assertOk();

    Http::assertNothingSent();
});

it('is off by default in the test environment and keeps the length rule when on', function () {
    expect(PasswordPolicy::rule())->toBeInstanceOf(Illuminate\Validation\Rules\Password::class);
    config(['portal.password_breach_check' => false]);
    Http::fake();

    $this->postJson('/api/v1/auth/reset-password', secResetBody($this->user, 'short-1'))->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson('/api/v1/auth/reset-password', secResetBody($this->user, str_repeat('a', 256)))->assertStatus(422)->assertJsonValidationErrors('password');
    Http::assertNothingSent();
});

it('does not ask the service for a request that already failed a cheaper rule', function () {
    Http::fake();

    $body = secResetBody($this->user, SEC_CLEAN);
    $body['password_confirmation'] = 'something else entirely';
    $this->postJson('/api/v1/auth/reset-password', $body)->assertStatus(422)->assertJsonValidationErrors('password');

    Http::assertNothingSent();
});

it('uses the fail-open verifier with a 2 second timeout', function () {
    expect(app(UncompromisedVerifier::class))->toBeInstanceOf(FailOpenPwnedVerifier::class)->and(config('portal.password_breach_timeout'))->toBe(2);
});

it('keeps both reset-type mails encrypted in the queue, so a queued payload never holds a token in clear text', function () {
    expect(new ResetPasswordNotification('t'))->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and(new UserInvitationMail('https://x.test/a', 7))->toBeInstanceOf(ShouldBeEncrypted::class);
});
