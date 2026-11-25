<?php

namespace App\Support;

class Mm
{
    /** numeric(7,2) arrives as a string: 25.00 becomes 25, 16.25 stays 16.25. */
    public static function number(string|int|float|null $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $float = (float) $value;

        return $float == floor($float) ? (int) $float : $float;
    }
}
