<?php

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared rules of the /admin lists: page 1 to 10000, per_page 1 to 100 (default 25), a sort whitelist, `q` max 64, CSV parameters.
 * Every error is keyed by the parameter name (never `status.0`). The role middleware and policies do the access control.
 */
abstract class AdminListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return list<string> allowed `sort` values */
    abstract protected function sorts(): array;

    protected function defaultSort(): string
    {
        return $this->sorts()[0];
    }

    /** @return array<string, array<int, mixed>> */
    protected function pagingRules(): array
    {
        return [
            'sort' => ['bail', 'nullable', 'string', Rule::in($this->sorts())],
            'page' => ['bail', 'nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['bail', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return list<mixed> */
    protected function qRules(): array
    {
        return ['bail', 'nullable', 'string', 'max:64', fn (string $attribute, mixed $value, Closure $fail) => self::clean($value)
            ? null : $fail("The {$attribute} contains invalid characters.")];
    }

    /**
     * A comma separated list: clean text, no empty items, at most $max items, each item accepted by $item.
     *
     * @param  Closure(string): bool  $item
     */
    protected function csvRule(int $max, Closure $item): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($max, $item) {
            $items = self::clean($value) ? self::split($value) : null;

            if ($items === null || count($items) > $max || in_array(false, array_map($item, $items), true)) {
                $fail("The {$attribute} must be a comma separated list of up to {$max} valid values.");
            }
        };
    }

    /** @param  list<string>  $allowed */
    protected function inList(array $allowed, int $max): Closure
    {
        return $this->csvRule($max, fn (string $i) => in_array($i, $allowed, true));
    }

    /** @return list<string> unique, trimmed items of a validated CSV parameter */
    protected function csv(string $key): array
    {
        $value = $this->validated($key);

        return is_string($value) ? array_values(array_unique(self::split($value) ?? [])) : [];
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 25);
    }

    public function sort(): string
    {
        return $this->validated('sort') ?? $this->defaultSort();
    }

    /** The validated q, or null. */
    public function q(): ?string
    {
        $q = $this->validated('q');

        return is_string($q) && $q !== '' ? $q : null;
    }

    /** @return list<string>|null null when an item is empty */
    protected static function split(string $value): ?array
    {
        $items = array_map('trim', explode(',', $value));

        return in_array('', $items, true) ? null : $items;
    }

    protected static function clean(mixed $value): bool
    {
        return is_string($value) && ! str_contains($value, "\0") && mb_check_encoding($value, 'UTF-8');
    }
}
