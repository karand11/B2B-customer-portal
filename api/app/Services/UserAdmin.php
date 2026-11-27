<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Account management by admins. Every action runs in one transaction with its audit row. Actions that could remove the last
 * administrator first take one advisory lock (ADMIN_SET_LOCK) and only then read the admin set, so two admins cannot demote or
 * deactivate each other at the same time. Sessions die through users.session_epoch (see EnsureUserIsActive). Email is immutable: there is no method for it.
 */
class UserAdmin
{
    /** Key of the transaction-level advisory lock that serialises every action which can reduce the number of active administrators. */
    public const ADMIN_SET_LOCK = 7_206_001;

    public function rename(User $actor, User $target, string $name): User
    {
        return DB::transaction(function () use ($actor, $target, $name) {
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $before = $locked->name;

            $locked->forceFill(['name' => $name]);
            if ($locked->isDirty()) {
                $locked->save();
                AuditLog::record('user.updated', 'user', (string) $locked->id, $locked->email, $actor, ['name' => $before], ['name' => $name], [], $locked->company_id);
            }

            return $locked;
        });
    }

    /** Role and company in one step (the invariants live in User::assign). The user must sign in again; their cart lines are deleted. */
    public function assign(User $actor, User $target, string $role, ?int $companyId): User
    {
        return DB::transaction(function () use ($actor, $target, $role, $companyId) {
            $admins = $this->lockActiveAdmins();
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();

            $locked->is($actor) && $this->refuse('user', 'You cannot change your own role or company.');

            $company = $role === 'customer' ? Company::findOrFail($companyId) : null;
            $roleBefore = $locked->primaryRole();
            $companyBefore = $locked->company_id;

            if ($roleBefore === $role && $companyBefore === $company?->id) {
                return $locked; // a no-op: no audit row, no epoch bump
            }

            $roleBefore === 'admin' && $role !== 'admin' && $this->isLastAdmin($locked, $admins)
                && $this->refuse('role', 'At least one active administrator is required.');

            $locked->forceFill(['session_epoch' => $locked->session_epoch + 1])->assign($role, $company);
            CartItem::where('user_id', $locked->id)->delete();

            AuditLog::record(
                'user.assignment_changed', 'user', (string) $locked->id, $locked->email, $actor,
                ['role' => $roleBefore, 'company_id' => $companyBefore], ['role' => $role, 'company_id' => $company?->id], [], $company?->id ?? $companyBefore,
            );

            return $locked;
        });
    }

    /** Sessions die on the next request; reset and invitation tokens are deleted. A pending user is revoked, not deactivated. */
    public function deactivate(User $actor, User $target): User
    {
        return DB::transaction(function () use ($actor, $target) {
            $admins = $this->lockActiveAdmins();
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();

            $locked->is($actor) && $this->refuse('user', 'You cannot deactivate your own account.');
            $locked->status() === 'pending' && $this->refuse('user', 'This user has a pending invitation. Revoke the invitation instead.');

            if (! $locked->active) {
                return $locked; // idempotent
            }

            $this->isLastAdmin($locked, $admins) && $this->refuse('user', 'At least one active administrator is required.');

            $locked->forceFill(['active' => false, 'session_epoch' => $locked->session_epoch + 1])->save();
            $this->deleteTokens($locked);
            AuditLog::record('user.deactivated', 'user', (string) $locked->id, $locked->email, $actor, ['active' => true], ['active' => false], [], $locked->company_id);

            return $locked;
        });
    }

    public function reactivate(User $actor, User $target): User
    {
        return DB::transaction(function () use ($actor, $target) {
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($locked->active) {
                return $locked; // idempotent
            }
            $locked->hasOpenInvitation() && $this->refuse('user', 'This user never accepted an invitation. Send a new invitation instead.');

            // The old sessions stay dead: the epoch moves again.
            $locked->forceFill(['active' => true, 'session_epoch' => $locked->session_epoch + 1])->save();
            AuditLog::record('user.reactivated', 'user', (string) $locked->id, $locked->email, $actor, ['active' => false], ['active' => true], [], $locked->company_id);

            return $locked;
        });
    }

    /** Queues the standard reset email for an active user. It never sets a password. */
    public function requestPasswordReset(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target) {
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();

            $locked->canSignIn() || $this->refuse('user', 'A password reset can only be requested for an active user.');
            Password::broker('users')->sendResetLink(['email' => $locked->email]) === Password::RESET_LINK_SENT
                || $this->refuse('user', 'A reset email was requested less than a minute ago. Try again shortly.');

            AuditLog::record('user.password_reset_requested', 'user', (string) $locked->id, $locked->email, $actor, [], [], [], $locked->company_id, always: true);
        });
    }

    /**
     * Takes THE lock of the admin set, then reads it. Every action that can reduce the number of active administrators (a role
     * change away from admin, a deactivation) calls this first, before it reads or locks anything else, so they run one after the other.
     * The read is a separate statement run after the lock: under READ COMMITTED it sees what the previous holder committed
     * (locking and joining the role rows in one statement would re-check only the locked user rows and keep the old role snapshot).
     *
     * @return list<int> ids of the active, accepted administrators
     */
    private function lockActiveAdmins(): array
    {
        DB::select('select pg_advisory_xact_lock('.(int) self::ADMIN_SET_LOCK.')');

        return User::query()
            ->join('model_has_roles', fn ($join) => $join->on('model_has_roles.model_id', '=', 'users.id')->where('model_has_roles.model_type', (new User)->getMorphClass()))
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'admin')
            ->where('users.active', true)
            ->whereRaw('not (users.invited_at is not null and users.invitation_accepted_at is null)')
            ->orderBy('users.id')
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @param  list<int>  $admins */
    private function isLastAdmin(User $user, array $admins): bool
    {
        return in_array($user->id, $admins, true) && count($admins) <= 1;
    }

    private function deleteTokens(User $user): void
    {
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        DB::table('user_invitation_tokens')->where('email', $user->email)->delete();
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
