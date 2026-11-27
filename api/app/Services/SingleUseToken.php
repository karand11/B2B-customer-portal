<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;

/**
 * Makes a reset or invitation token single use. The framework broker checks the token, runs the callback and only then deletes
 * the token, with no lock in between, so parallel requests with one token would all succeed. Call this INSIDE the broker callback:
 * it locks the user row, checks the token again under the lock, deletes it and runs $apply, all in one transaction. A second
 * request waits on the row lock and then finds no token.
 */
class SingleUseToken
{
    /**
     * @param  Closure(User): void  $apply  runs on the freshly locked user, inside the transaction
     * @return bool false when the token was already used (or the user is gone): nothing was changed
     */
    public static function consume(PasswordBroker $broker, User $user, string $token, Closure $apply): bool
    {
        return DB::transaction(function () use ($broker, $user, $token, $apply) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();

            if ($locked === null || ! $broker->getRepository()->exists($locked, $token)) {
                return false;
            }

            $broker->getRepository()->delete($locked);
            $apply($locked);

            return true;
        });
    }
}
