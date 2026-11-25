<?php

namespace App\Support;

class PartNumber
{
    /**
     * Must behave exactly like the generated column products.part_number_search:
     * lower(regexp_replace(part_number, '[^A-Za-z0-9]', '', 'g')). ASCII only, no /u flag.
     */
    public static function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value));
    }
}
