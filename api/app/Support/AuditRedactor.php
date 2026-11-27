<?php

namespace App\Support;

/**
 * The audit log stores ONLY what is on these allowlists (docs/api-contract.md, "Audit log"). Everything else is dropped
 * silently, on write and again on read, so a caller that passes a model's attributes cannot leak a password, token, note or address.
 * Values must be scalars or null; strings are cut at 200 characters.
 */
class AuditRedactor
{
    private const PRODUCT = [
        'part_number', 'name', 'brand', 'category_id', 'inner_diameter_mm', 'outer_diameter_mm', 'width_mm', 'material',
        'seal_type', 'price_cents', 'price_on_request', 'stock_status', 'active',
    ];

    public const CHANGES = [
        'order' => ['status'],
        'quote' => ['status', 'valid_until', 'subtotal_cents', 'total_cents', 'revision'],
        'invoice' => ['status', 'total_cents', 'due_date', 'paid_on'],
        'product' => self::PRODUCT,
        'company' => ['name', 'tax_id'],
        'user' => ['name', 'role', 'company_id', 'active'],
        'import' => [],
    ];

    public const METADATA = [
        'order' => [],
        'quote' => ['order_number', 'lines_changed'],
        'invoice' => ['invoice_number', 'order_number'],
        'product' => ['description_changed'],
        'company' => ['billing_address_changed'],
        'user' => [],
        'import' => ['file_name', 'sha256', 'rows_read', 'created', 'updated', 'bad_rows', 'applied_rows', 'skipped_rows'],
    ];

    private const MAX_STRING = 200;

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, list<string>>  $allowlist  CHANGES or METADATA
     * @return array<string, scalar|null>
     */
    public static function filter(string $subjectType, array $values, array $allowlist): array
    {
        $out = [];

        foreach ($allowlist[$subjectType] ?? [] as $key) {
            if (! array_key_exists($key, $values) || (! is_scalar($values[$key]) && $values[$key] !== null)) {
                continue;
            }

            $value = $values[$key];
            $out[$key] = is_string($value) ? mb_substr($value, 0, self::MAX_STRING) : $value;
        }

        return $out;
    }

    /**
     * Read-side filter for a stored `changes` value: both sides through the allowlist again.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, array<string, scalar|null>>
     */
    public static function changes(string $subjectType, array $changes): array
    {
        $out = [];

        foreach (['before', 'after'] as $side) {
            $values = is_array($changes[$side] ?? null) ? $changes[$side] : null;
            $values === null || $out[$side] = self::filter($subjectType, $values, self::CHANGES);
        }

        return $out;
    }
}
