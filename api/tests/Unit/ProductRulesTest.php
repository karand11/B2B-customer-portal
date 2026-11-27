<?php

use App\Support\ProductRules;

/** @return list<string> the failure messages of one rule closure for one value */
function productRulesFailures(Closure $rule, mixed $value): array
{
    $failures = [];
    $rule('field', $value, function (string $message) use (&$failures) {
        $failures[] = $message;
    });

    return $failures;
}

it('accepts millimetre values with at most two decimals above 0 and up to 5000', function (mixed $value) {
    expect(productRulesFailures(ProductRules::mm(), $value))->toBe([]);
})->with([25, 0.01, '0.01', 5000, '5000.00', 16.25, '16.5', 4999.99]);

it('rejects the rest', function (mixed $value) {
    expect(productRulesFailures(ProductRules::mm(), $value))->toHaveCount(1);
})->with([0, '0', -1, 5000.01, '5001', 1.234, '1e1', 'abc', '', true, null, [[1]], '+5', ' 5', '5 ', 12345]);

it('writes a millimetre value in the form Postgres returns, so an unchanged value is not dirty', function () {
    expect(ProductRules::mmValue(25))->toBe('25.00')->and(ProductRules::mmValue('16.5'))->toBe('16.50')->and(ProductRules::mmValue(0.01))->toBe('0.01');
});

it('rejects a spreadsheet formula prefix on any text', function (string $value, bool $rejected) {
    expect(productRulesFailures(ProductRules::noFormula(), $value) !== [])->toBe($rejected);
})->with([['=1+1', true], ['+1', true], ['-1', true], ['@SUM', true], ["\t=1", true], ["\r=1", true], [' =1', true], ['Bearing = good', false], ['6205 - 2RS', false], ['x+y', false]]);

it('has the part number and text patterns of the importer', function () {
    expect(preg_match(ProductRules::PART_NUMBER_PATTERN, '6205-2RS'))->toBe(1)->and(preg_match(ProductRules::PART_NUMBER_PATTERN, '-6205'))->toBe(0)
        ->and(preg_match(ProductRules::PART_NUMBER_PATTERN, "6205\n"))->toBe(0)->and(preg_match(ProductRules::TEXT_PATTERN, 'Chrome steel'))->toBe(1)
        ->and(preg_match(ProductRules::TEXT_PATTERN, '=Steel'))->toBe(0);
});
