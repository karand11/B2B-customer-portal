<?php

namespace Tests;

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

        return parent::setUpTraits();
    }
}
