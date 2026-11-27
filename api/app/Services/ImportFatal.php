<?php

namespace App\Services;

use RuntimeException;

/** Fatal import problem (the CLI exits with code 2, the API answers 422 `file`): nothing was imported. */
class ImportFatal extends RuntimeException
{
    /** @var list<string> warnings collected before the problem was found (the CLI still prints them first) */
    public array $warnings = [];
}
