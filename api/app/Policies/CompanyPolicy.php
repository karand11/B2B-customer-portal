<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/** Staff may read companies and fix the billing data needed to issue an invoice; only admins create or rename. Plain 403 on failure. */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Company $company): bool
    {
        return $user->isStaff();
    }

    public function updateBilling(User $user, Company $company): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasRole('admin');
    }
}
