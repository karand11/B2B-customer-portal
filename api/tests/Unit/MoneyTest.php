<?php

use App\Support\Money;

it('formats integer cents with thousands separators and two decimals, independent of locale', function (int $cents, string $text) {
    expect(Money::amount($cents))->toBe($text)->and(Money::format($cents, 'USD'))->toBe("USD {$text}");
})->with([
    [0, '0.00'], [5, '0.05'], [99, '0.99'], [100, '1.00'], [123456, '1,234.56'], [100000000, '1,000,000.00'],
    [1_499_850_000_000_000, '14,998,500,000,000.00'], [-250, '-2.50'],
]);

it('formats basis points as a percent with integer math', function (int $bp, string $text) {
    expect(Money::percent($bp))->toBe($text);
})->with([[0, '0%'], [1900, '19%'], [750, '7.5%'], [725, '7.25%'], [5, '0.05%'], [5000, '50%']]);
