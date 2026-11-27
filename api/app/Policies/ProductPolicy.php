<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Back-office product management: staff and admin (the role middleware already gates the routes; this is the single place for the rule
 * and covers non-HTTP callers). A plain 403 on failure: staff may see every product, so there is nothing to hide with a 404.
 * No Gate::before and no blanket admin allow.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Product $product): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Product $product): bool
    {
        return $user->isStaff();
    }

    public function activate(User $user, Product $product): bool
    {
        return $user->isStaff();
    }

    public function import(User $user): bool
    {
        return $user->isStaff();
    }
}
