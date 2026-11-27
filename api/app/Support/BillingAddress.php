<?php

namespace App\Support;

use Closure;

/** A company billing address as printed on invoices (up to 6 lines, the rule IssueInvoice's buyer snapshot expects). */
class BillingAddress
{
    public const MAX_LINES = 6;

    public const MAX_LINE = 80;

    public const MAX_TOTAL = 500;

    /**
     * CRLF becomes LF, each line is trimmed, blank lines are dropped. Null is returned for an empty address.
     *
     * @return array{0: string|null, 1: string|null} [normalised address, error message]
     */
    public static function normalise(string $raw): array
    {
        if (! mb_check_encoding($raw, 'UTF-8') || preg_match('/(?![\t\n\r])\p{Cc}|\p{Cf}/u', $raw)) {
            return [null, 'The billing address contains invalid characters.'];
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r\n", "\n", $raw))), fn ($l) => $l !== ''));

        return match (true) {
            str_contains(implode('', $lines), "\r") => [null, 'The billing address contains invalid characters.'],
            count($lines) > self::MAX_LINES => [null, 'The billing address can have at most '.self::MAX_LINES.' lines.'],
            collect($lines)->contains(fn ($l) => mb_strlen($l) > self::MAX_LINE) => [null, 'Each billing address line can have at most '.self::MAX_LINE.' characters.'],
            mb_strlen(implode("\n", $lines)) > self::MAX_TOTAL => [null, 'The billing address can have at most '.self::MAX_TOTAL.' characters.'],
            default => [$lines === [] ? null : implode("\n", $lines), null],
        };
    }

    /** Validation closure for a nullable string field. */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $error = is_string($value) ? self::normalise($value)[1] : null;
            $error === null || $fail($error);
        };
    }
}
