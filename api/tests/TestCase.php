<?php

namespace Tests;

use App\Services\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase wipes the database in setUpTraits(), so guard first: never touch a non-test DB.
     */
    protected function setUpTraits()
    {
        $name = DB::connection()->getDatabaseName();

        if (! str_ends_with((string) $name, '_test')) {
            throw new RuntimeException("Refusing to run tests against database [$name]: name must end with _test.");
        }

        $uses = parent::setUpTraits();

        // RefreshDatabase has opened its wrapping transaction: AuditLog must see a transaction of the CALLER on top of it.
        AuditLog::$baseTransactionLevel = DB::transactionLevel();

        return $uses;
    }

    protected function tearDown(): void
    {
        AuditLog::$baseTransactionLevel = 0;

        parent::tearDown();
    }

    /**
     * actingAs() skips the login endpoint, so it would also skip the session epoch the `active` middleware compares.
     * Store the user's current epoch and role like a real login does (a test for a stale epoch overrides it with withSession()).
     */
    public function be(Authenticatable $user, $guard = null)
    {
        // `role` is what the login endpoint stores for the api rate limiter (read without loading the relation onto the model).
        $this->withSession(['session_epoch' => $user->session_epoch ?? 1, 'role' => $user->roles()->pluck('name')->first()]);

        return parent::be($user, $guard);
    }
}
