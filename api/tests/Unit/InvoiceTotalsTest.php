<?php

use App\Support\InvoiceTotals;

// Hand-calculated in docs/phase-5-design.md section 5.2: tax = intdiv(subtotal * rate + 5000, 10000), once, on the subtotal.
dataset('vat fixtures', [
    'F1 exact 19% of 100.00' => [10000, 1900, 1900, 11900],
    'F2 rounds to nearest' => [999, 1900, 190, 1189],
    'F3 exact half rounds up' => [5, 1000, 1, 6],
    'F4 half up, not banker' => [25, 1000, 3, 28],
    'F5a just below half' => [499, 10, 0, 499],
    'F5b exactly half' => [500, 10, 1, 501],
    'F6 rate 0' => [12345, 0, 0, 12345],
    'F7 mixed lines, rounded once' => [107079, 1900, 20345, 127424],
    'F8 zero order' => [0, 1900, 0, 0],
    'F9 maximum, no overflow' => [999_900_000_000_000, 5000, 499_950_000_000_000, 1_499_850_000_000_000],
]);

it('computes tax and total exactly as the hand-calculated fixtures', function (int $subtotal, int $rate, int $tax, int $total) {
    expect(InvoiceTotals::compute($subtotal, $rate))->toBe(['tax' => $tax, 'total' => $total]);
})->with('vat fixtures');

it('keeps the maximum total exact in a JavaScript number', function () {
    expect(InvoiceTotals::compute(InvoiceTotals::MAX_SUBTOTAL, 5000)['total'])->toBeLessThan(9_007_199_254_740_991);
});

it('shows why rounding once differs from per-line rounding (fixture F7)', function () {
    $perLine = array_sum(array_map(fn ($line) => intdiv($line * 1900 + 5000, 10000), [3750, 3330, 99999]));

    expect($perLine)->toBe(20346)->and(InvoiceTotals::compute(107079, 1900)['tax'])->toBe(20345);
});

it('refuses values outside the proven bounds instead of overflowing', function (int $subtotal, int $rate) {
    expect(fn () => InvoiceTotals::compute($subtotal, $rate))->toThrow(InvalidArgumentException::class);
})->with([[-1, 0], [InvoiceTotals::MAX_SUBTOTAL + 1, 0], [100, -1], [100, 5001]]);
