<?php

use App\Support\BillingAddress;

it('normalises line breaks, trims lines, drops blank lines and returns null for empty', function (string $raw, ?string $expected) {
    expect(BillingAddress::normalise($raw))->toBe([$expected, null]);
})->with([
    'crlf' => ["a\r\nb", "a\nb"],
    'trim and blanks' => ["  a  \n\n   \n b\t\n", "a\nb"],
    'single line' => ['Only line', 'Only line'],
    'empty' => ['', null],
    'only blanks' => ["\n \r\n\t", null],
    'unicode' => ["Straße 1\nMünchen", "Straße 1\nMünchen"],
]);

it('enforces six lines, 80 characters per line and 500 in total', function (string $raw, string $needle) {
    [$address, $error] = BillingAddress::normalise($raw);

    expect($address)->toBeNull()->and($error)->toContain($needle);
})->with([
    'seven lines' => [implode("\n", array_fill(0, 7, 'x')), 'at most 6 lines'],
    'long line' => [str_repeat('a', 81), 'at most 80'],
    'long total' => [implode("\n", array_fill(0, 6, str_repeat('b', 80))).str_repeat('c', 20), 'at most'],
]);

it('accepts the exact limits', function () {
    $six = implode("\n", array_fill(0, 6, str_repeat('a', 80)));

    expect(strlen($six))->toBe(485)->and(BillingAddress::normalise($six)[0])->toBe($six);
});

it('rejects control, format and invalid characters', function (string $raw) {
    expect(BillingAddress::normalise($raw)[1])->toContain('invalid characters');
})->with(["a\x07b", "a\u{200B}b", "a\u{202E}b", "a\rb", "a\0b", "a\xB1b"]);
