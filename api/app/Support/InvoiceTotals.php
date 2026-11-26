<?php

namespace App\Support;

use InvalidArgumentException;

/** The only place the VAT formula lives. Integer cents and basis points only: no float, no round(), no bcmath. */
class InvoiceTotals
{
    public const MAX_SUBTOTAL = 999_900_000_000_000;

    public const MAX_RATE_BP = 5000;

    /**
     * Tax is rounded ONCE, on the subtotal, half up: intdiv(subtotal * rate + 5000, 10000).
     * The bounds guarantee no overflow (4.9995e18 < PHP_INT_MAX) and a JS-safe total.
     *
     * @return array{tax: int, total: int}
     */
    public static function compute(int $subtotalCents, int $rateBp): array
    {
        if ($subtotalCents < 0 || $subtotalCents > self::MAX_SUBTOTAL || $rateBp < 0 || $rateBp > self::MAX_RATE_BP) {
            throw new InvalidArgumentException('Subtotal or VAT rate out of range.');
        }

        $tax = intdiv($subtotalCents * $rateBp + 5000, 10000);

        return ['tax' => $tax, 'total' => $subtotalCents + $tax];
    }
}
