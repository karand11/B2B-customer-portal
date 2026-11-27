<?php

namespace App\Policies;

use App\Models\User;

/**
 * Account management is admin only. The rules about the admin's OWN row (no self-demotion, no self-deactivation) and about the last
 * administrator are state rules in UserAdmin: they answer 422 on a named field, not 403 (contract).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, User $target): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasRole('admin');
    }

    public function assign(User $user, User $target): bool
    {
        return $user->hasRole('admin');
    }

    public function deactivate(User $user, User $target): bool
    {
        return $user->hasRole('admin');
    }

    public function reactivate(User $user, User $target): bool
    {
        return $user->hasRole('admin');
    }

    public function resetPassword(User $user, User $target): bool
    {
        return $user->hasRole('admin');
    }

    /** Resend and revoke an invitation. */
    public function manageInvitation(User $user, User $target): bool
    {
        return $user->hasRole('admin');
    }
}
