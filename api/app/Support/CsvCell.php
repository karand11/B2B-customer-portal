<?php

namespace App\Support;

/**
 * Spreadsheet formula injection guard for any future CSV/XLSX export: a text cell that starts with = + - @ (or a tab or carriage return,
 * also after leading spaces) would be run as a formula by Excel and LibreOffice, so it gets a leading apostrophe. Pass EVERY cell through it.
 * Numbers and null pass unchanged (a negative number is data, not text).
 */
class CsvCell
{
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match('/^ *[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
