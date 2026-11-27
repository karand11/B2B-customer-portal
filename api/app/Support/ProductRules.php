<?php

namespace App\Support;

use Closure;

/**
 * The product field rules shared by the admin forms and the CSV importer (one source for patterns, limits and the formula-prefix rule),
 * so a value the form accepts is a value the CSV accepts and vice versa. The importer keeps its own per-row messages (Phase 2 output).
 */
class ProductRules
{
    /** Part number as typed: starts with a letter or digit, then letters, digits, space . _ / - */
    public const PART_NUMBER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ._\/-]*$/D';

    /** Brand and material: starts with a letter or digit (no spreadsheet formula prefix). */
    public const TEXT_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N} .&\/+-]*$/uD';

    /** Values starting with these are executed as formulas by spreadsheet software (CSV injection). */
    public const FORMULA_PREFIX = '/^[=+\-@\t\r]/';

    public const MAX_MM = 5000;

    /** name, description: rejects a spreadsheet formula prefix. */
    public static function noFormula(): Closure
    {
        return fn (string $attribute, mixed $value, Closure $fail) => is_string($value) && preg_match(self::FORMULA_PREFIX, ltrim($value, ' ')) === 1
            ? $fail("The {$attribute} must not start with = + - @ or a tab (spreadsheet formula).") : null;
    }

    /** A millimetre value: JSON number or numeric string, at most 2 decimals, above 0 and at most 5000. */
    public static function mm(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $text = self::mmText($value);

            if ($text === null || preg_match('/^\d{1,4}(\.\d{1,2})?$/D', $text) !== 1 || (float) $text <= 0 || (float) $text > self::MAX_MM) {
                $fail("The {$attribute} must be a number above 0 and up to ".self::MAX_MM.' with at most 2 decimals.');
            }
        };
    }

    /** The validated mm value as numeric(7,2) text ("25.00"), the form Postgres returns, so an unchanged value is not "dirty". */
    public static function mmValue(mixed $value): string
    {
        return number_format((float) self::mmText($value), 2, '.', '');
    }

    private static function mmText(mixed $value): ?string
    {
        return is_int($value) || is_float($value) || is_string($value) ? (string) $value : null;
    }
}
