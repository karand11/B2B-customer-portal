<?php

namespace App\Support;

/**
 * The one PO reference rule, shared by checkout, quote create and quote accept.
 * Checked on the RAW value (TrimStrings skips po_reference, see bootstrap/app.php) so a trailing NUL is still visible.
 */
class PoReference
{
    private const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ._\/#-]*$/D';

    /** The whitespace trimmed at both ends: exactly what JavaScript's String.trim() strips, like the SPA. Control characters (NUL, DEL, U+0085) and U+200B are NOT whitespace here, so they stay and fail the pattern. */
    private const TRIM = '/^[\t\n\x0B\f\r\p{Zs}\x{2028}\x{2029}\x{FEFF}]+|[\t\n\x0B\f\r\p{Zs}\x{2028}\x{2029}\x{FEFF}]+$/u';

    /** The validation message for a raw value, or null when it is acceptable (empty counts as acceptable: it becomes NULL). */
    public static function error(string $value): ?string
    {
        $trimmed = self::trimmed($value);

        return match (true) {
            str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8') => 'The PO reference contains invalid characters.',
            $trimmed === null => null,
            mb_strlen($trimmed) > 64 => 'The PO reference must not be longer than 64 characters.',
            preg_match(self::PATTERN, $trimmed) !== 1 => 'The PO reference must start with a letter or digit and contain only letters, digits, spaces and . - _ / #.',
            default => null,
        };
    }

    /** Trims leading and trailing whitespace; '' becomes null. Invalid UTF-8 never gets here (rejected first). */
    public static function trimmed(string $value): ?string
    {
        $trimmed = mb_check_encoding($value, 'UTF-8') ? preg_replace(self::TRIM, '', $value) : $value;

        return $trimmed === '' ? null : $trimmed;
    }
}
