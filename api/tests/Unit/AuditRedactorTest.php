<?php

use App\Support\AuditRedactor;

it('keeps only allowlisted keys per subject type', function () {
    $values = ['status' => 'confirmed', 'password' => 'x', 'name' => 'n', 'internal_note' => 'secret'];

    expect(AuditRedactor::filter('order', $values, AuditRedactor::CHANGES))->toBe(['status' => 'confirmed'])
        ->and(AuditRedactor::filter('user', $values, AuditRedactor::CHANGES))->toBe(['name' => 'n'])
        ->and(AuditRedactor::filter('import', $values, AuditRedactor::CHANGES))->toBe([])
        ->and(AuditRedactor::filter('unknown', $values, AuditRedactor::CHANGES))->toBe([]);
});

it('allows scalars and null, drops arrays and objects, and cuts strings at 200 characters', function () {
    $out = AuditRedactor::filter('product', [
        'name' => str_repeat('é', 300), 'price_cents' => 5, 'price_on_request' => false, 'brand' => null, 'material' => ['x'], 'seal_type' => new stdClass, 'width_mm' => 1.5,
    ], AuditRedactor::CHANGES);

    expect(mb_strlen($out['name']))->toBe(200)->and($out)->toMatchArray(['price_cents' => 5, 'price_on_request' => false, 'brand' => null, 'width_mm' => 1.5])
        ->and($out)->not->toHaveKeys(['material', 'seal_type']);
});

it('filters both sides of a stored change on the way out and drops anything that is not a map', function () {
    expect(AuditRedactor::changes('company', ['before' => ['name' => 'a', 'billing_address' => 'x'], 'after' => ['name' => 'b', 'tax_id' => 'T'], 'extra' => 1]))
        ->toBe(['before' => ['name' => 'a'], 'after' => ['name' => 'b', 'tax_id' => 'T']])
        ->and(AuditRedactor::changes('company', ['before' => 'oops']))->toBe([])
        ->and(AuditRedactor::changes('company', []))->toBe([]);
});

it('never allows a password, token, hash, note, reason, description, address, email body or epoch for any subject', function () {
    $forbidden = ['password', 'token', 'remember_token', 'session_epoch', 'internal_note', 'staff_note', 'customer_note', 'reason', 'description', 'billing_address', 'email_body', 'email', 'ip', 'user_agent'];
    $allowed = collect([AuditRedactor::CHANGES, AuditRedactor::METADATA])->flatMap(fn ($map) => collect($map)->flatten())->unique()->all();

    expect(array_intersect($forbidden, $allowed))->toBe([]);
});

it('allows the import file name, its sha256 and the row counts in the import metadata, and nothing else', function () {
    $values = ['file_name' => 'catalog.csv', 'sha256' => str_repeat('a', 64), 'rows_read' => 3, 'created' => 1, 'updated' => 1, 'bad_rows' => 1, 'applied_rows' => 2, 'skipped_rows' => 1, 'content' => 'a,b', 'errors' => ['x']];

    expect(AuditRedactor::filter('import', $values, AuditRedactor::METADATA))->toBe(array_diff_key($values, ['content' => 1, 'errors' => 1]));
});
