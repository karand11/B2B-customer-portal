<?php

namespace App\Services;

use App\Exceptions\InvitationNotPending;
use App\Mail\UserInvitationMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Invite, resend, revoke and accept. Tokens come from Laravel's password broker `invitations` (hashed, single use, 7 days, own table).
 * The invited user starts with a random 64-character password nobody knows. The mail is queued AFTER the transaction and a mail
 * problem never fails the request (the admin uses resend). The link is built from config('app.web_url'), never from the request Host.
 */
class UserInvitations
{
    /** @param  array{name: string, email: string, role: string, company_id: int|null}  $data
     * @return array{0: User, 1: bool} the new (pending) user and whether the email was queued
     */
    public function invite(User $admin, array $data): array
    {
        $token = null;

        try {
            $user = DB::transaction(function () use ($admin, $data, &$token) {
                $company = $data['role'] === 'customer' ? Company::findOrFail($data['company_id']) : null;

                $user = (new User)->forceFill([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Str::random(64),
                    'active' => true,
                    'invited_at' => now(),
                    'invited_by_user_id' => $admin->id,
                ])->assign($data['role'], $company);

                $token = $this->broker()->createToken($user);

                AuditLog::record(
                    'user.invited', 'user', (string) $user->id, $user->email, $admin,
                    [], ['name' => $user->name, 'role' => $data['role'], 'company_id' => $user->company_id, 'active' => true], [], $user->company_id,
                );

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['email' => 'This email is already in use.']);
        }

        return [$user, $this->queue($user, $token)];
    }

    /** Pending or revoked users only. A new token replaces the old one; 60 seconds between invitations. */
    public function resend(User $admin, User $target): array
    {
        $token = null;

        $user = DB::transaction(function () use ($admin, $target, &$token) {
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();

            $locked->hasOpenInvitation() || $this->refuse('This user has no open invitation.');
            $this->broker()->getRepository()->recentlyCreatedToken($locked) && $this->refuse('An invitation was sent less than a minute ago. Try again shortly.');

            $locked->forceFill(['active' => true, 'invited_at' => now()])->save();
            $token = $this->broker()->createToken($locked);

            AuditLog::record('user.invitation_resent', 'user', (string) $locked->id, $locked->email, $admin, [], [], [], $locked->company_id, always: true);

            return $locked;
        });

        return [$user, $this->queue($user, $token)];
    }

    /** Pending users only; revoking an already revoked invitation is a no-op. The row and the email address stay reserved. */
    public function revoke(User $admin, User $target): User
    {
        return DB::transaction(function () use ($admin, $target) {
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();

            $locked->hasOpenInvitation() || $this->refuse('This user has no open invitation.');

            if ($locked->active) {
                $locked->forceFill(['active' => false, 'session_epoch' => $locked->session_epoch + 1])->save();
                $this->broker()->deleteToken($locked);
                AuditLog::record('user.invitation_revoked', 'user', (string) $locked->id, $locked->email, $admin, ['active' => true], ['active' => false], [], $locked->company_id);
            }

            return $locked;
        });
    }

    /**
     * Sets the password and finishes the account. False for EVERY expected failure (unknown email, wrong, used, expired or revoked token,
     * not pending): the caller answers one identical 422, so nothing tells the cases apart. A real failure (database, audit) is not
     * expected: it propagates (generic 500, logged) and the transaction rolls back. The token is consumed under the user's row lock
     * (SingleUseToken), so parallel requests cannot both succeed and a revoke that committed first wins.
     *
     * @param  array{token: string, email: string, password: string, password_confirmation: string}  $credentials
     */
    public function accept(array $credentials): bool
    {
        try {
            $status = $this->broker()->reset($credentials, function (User $user, string $password) use ($credentials) {
                SingleUseToken::consume($this->broker(), $user, $credentials['token'], function (User $locked) use ($password) {
                    $locked->status() === 'pending' || throw new InvitationNotPending;

                    $locked->forceFill([
                        'password' => $password, 'remember_token' => Str::random(60), 'invitation_accepted_at' => now(),
                        'email_verified_at' => now(), 'session_epoch' => $locked->session_epoch + 1,
                    ])->save();

                    // The accepting user is the actor.
                    AuditLog::record('user.invitation_accepted', 'user', (string) $locked->id, $locked->email, $locked, [], [], [], $locked->company_id, always: true);
                }) || throw new InvitationNotPending;
            });
        } catch (InvitationNotPending) {
            return false;
        }

        // Unknown users cost no hash check inside the broker: spend one so timing does not tell them apart.
        $status === Password::INVALID_USER && Hash::make(Str::random(40));

        return $status === Password::PASSWORD_RESET;
    }

    private function queue(User $user, string $token): bool
    {
        try {
            $link = rtrim(config('app.web_url'), '/').'/accept-invitation?token='.$token.'&email='.urlencode($user->email);
            Mail::to($user->email)->queue(new UserInvitationMail($link, (int) ceil(config('auth.passwords.invitations.expire') / 1440)));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function broker(): PasswordBroker
    {
        return Password::broker('invitations');
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['user' => $message]);
    }
}
