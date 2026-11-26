<?php

namespace App\Support;

use Closure;

/** Validation closures for JSON input (never trusted, never HTML). Run after TrimStrings. */
class InputRules
{
    /** One line of text: no control or format characters at all (NUL, ESC, DEL, U+0085, U+200B, newlines, bidi overrides). */
    public static function line(): Closure
    {
        return fn (string $attribute, mixed $value, Closure $fail) => is_string($value) && (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}]/u', $value))
            ? $fail("The {$attribute} contains invalid characters.") : null;
    }

    /** Free text: like line() but newline (LF, CRLF) and tab are allowed. */
    public static function text(): Closure
    {
        return fn (string $attribute, mixed $value, Closure $fail) => is_string($value) && (! mb_check_encoding($value, 'UTF-8') || preg_match('/(?![\t\n\r])\p{Cc}|\p{Cf}/u', $value))
            ? $fail("The {$attribute} contains invalid characters.") : null;
    }

    /** A real calendar date in strict YYYY-MM-DD form (no datetime) within the inclusive [min, max] bounds (also YYYY-MM-DD, either may be null). */
    public static function date(?string $min = null, ?string $max = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($min, $max) {
            if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $fail("The {$attribute} must be a valid date (YYYY-MM-DD).");
            } elseif (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
                $fail("The {$attribute} must be between ".($min ?? 'any date').' and '.($max ?? 'any date').'.');
            }
        };
    }

    /** A real JSON integer in [min, max]: "2", 1.5 and true are rejected (Laravel's `integer` rule accepts "2"). */
    public static function int(int $min, int $max): Closure
    {
        return fn (string $attribute, mixed $value, Closure $fail) => is_int($value) && $value >= $min && $value <= $max
            ? null : $fail("The {$attribute} must be a whole number between {$min} and {$max}.");
    }
}
