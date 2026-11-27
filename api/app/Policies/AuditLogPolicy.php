<?php

namespace App\Policies;

use App\Models\User;

/** The audit log also records staff behaviour and user management: admins only. */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
