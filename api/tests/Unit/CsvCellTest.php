<?php

use App\Support\CsvCell;

it('prefixes every spreadsheet formula trigger of the OWASP list with an apostrophe', function (string $value) {
    expect(CsvCell::safe($value))->toBe("'".$value);
})->with([
    'equals' => ['=1+1'], 'plus' => ['+1'], 'minus' => ['-1+1'], 'at' => ['@SUM(A1)'], 'tab' => ["\t=1"], 'carriage return' => ["\r=1"],
    'dde' => ['=cmd|\' /C calc\'!A0'], 'hyperlink' => ['=HYPERLINK("http://evil.test","x")'], 'after spaces' => ['   =1+1'], 'spaces then plus' => [' +49 30 123'],
    'minus word' => ['-text'], 'at sign only' => ['@'],
]);

it('leaves harmless text alone', function (string $value) {
    expect(CsvCell::safe($value))->toBe($value);
})->with(['plain' => ['6205-2RS'], 'empty' => [''], 'equals inside' => ['a=b'], 'minus inside' => ['SKF-6205'], 'unicode' => ['Wälzlager'], 'quote start' => ["'already"], 'digits' => ['123']]);

it('passes everything that is not a string through unchanged (a negative number is data)', function (mixed $value) {
    expect(CsvCell::safe($value))->toBe($value);
})->with([[-5], [0], [1.5], [null], [true], [false], [[1, 2]]]);
