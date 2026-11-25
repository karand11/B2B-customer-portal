<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Product;
use App\Support\PartNumber;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductsRequest extends FormRequest
{
    public const SORTS = [
        'part_number', 'brand', 'inner_diameter', 'outer_diameter', 'width', 'price',
        '-part_number', '-brand', '-inner_diameter', '-outer_diameter', '-width', '-price',
    ];

    /** request prefix => column */
    private const RANGES = ['inner_diameter' => 'inner_diameter_mm', 'outer_diameter' => 'outer_diameter_mm', 'width' => 'width_mm'];

    private const TEXT_PATTERN = '/^[\p{L}\p{N} .&\/+-]+$/uD';

    /** @var list<int> ids of the validated category slugs */
    private array $categoryIds = [];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'q' => ['bail', 'nullable', 'string', 'max:64', $this->cleanText()],
            'category' => ['bail', 'nullable', 'string', $this->csv(10, fn ($i) => preg_match('/^[a-z0-9-]{1,64}$/D', $i) === 1, $this->categoriesExist(...))],
            'brand' => ['bail', 'nullable', 'string', $this->csv(20, fn ($i) => $this->validText($i))],
            'material' => ['bail', 'nullable', 'string', $this->csv(20, fn ($i) => $this->validText($i))],
            'seal_type' => ['bail', 'nullable', 'string', $this->csv(3, fn ($i) => in_array($i, Product::SEAL_TYPES, true))],
            'stock_status' => ['bail', 'nullable', 'string', $this->csv(4, fn ($i) => in_array($i, Product::STOCK_STATUSES, true))],
            'sort' => ['bail', 'nullable', 'string', Rule::in(self::SORTS)],
            'page' => ['bail', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['bail', 'nullable', 'integer', 'min:1', 'max:100'],
        ];

        foreach (array_keys(self::RANGES) as $prefix) {
            foreach (['_min', '_max'] as $suffix) {
                $rules[$prefix.$suffix] = ['bail', 'nullable', 'string', $this->decimal()];
            }
        }

        return $rules;
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (array_keys(self::RANGES) as $prefix) {
                $min = $this->input($prefix.'_min');
                $max = $this->input($prefix.'_max');

                if (is_string($min) && is_string($max) && ! $validator->errors()->hasAny([$prefix.'_min', $prefix.'_max'])
                    && (float) $min > (float) $max) {
                    $validator->errors()->add($prefix.'_min', "The {$prefix}_min must not be greater than {$prefix}_max.");
                }
            }
        }];
    }

    /**
     * Normalised, typed search criteria (call only after validation).
     *
     * @return array<string, mixed>
     */
    public function criteria(): array
    {
        $v = $this->validated();
        $list = fn (string $key) => isset($v[$key]) ? array_values(array_unique(self::split($v[$key]))) : [];
        $ranges = [];

        foreach (self::RANGES as $prefix => $column) {
            $bounds = [$v[$prefix.'_min'] ?? null, $v[$prefix.'_max'] ?? null];
            if ($bounds !== [null, null]) {
                $ranges[$column] = $bounds;
            }
        }

        return [
            // null = no search; '' = a search that normalised to nothing (matches no rows).
            'q' => isset($v['q']) ? PartNumber::normalise($v['q']) : null,
            'category_ids' => $this->categoryIds,
            'brand' => $list('brand'),
            'material' => $list('material'),
            'seal_type' => $list('seal_type'),
            'stock_status' => $list('stock_status'),
            'ranges' => $ranges,
            'sort' => $v['sort'] ?? null,
            'page' => (int) ($v['page'] ?? 1),
            'per_page' => (int) ($v['per_page'] ?? 24),
        ];
    }

    /** @return list<string>|null null when the list has an empty item */
    private static function split(string $value): ?array
    {
        $items = array_map('trim', explode(',', $value));

        return in_array('', $items, true) ? null : $items;
    }

    private static function clean(string $value): bool
    {
        return ! str_contains($value, "\0") && mb_check_encoding($value, 'UTF-8');
    }

    private function validText(string $item): bool
    {
        return mb_strlen($item) <= 64 && preg_match(self::TEXT_PATTERN, $item) === 1;
    }

    private function cleanText(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! self::clean($value)) {
                $fail("The {$attribute} contains invalid characters.");
            }
        };
    }

    private function decimal(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (preg_match('/^\d{1,4}(\.\d{1,2})?$/D', $value) !== 1 || (float) $value < 0.01 || (float) $value > 5000) {
                $fail("The {$attribute} must be a number from 0.01 to 5000 with at most 2 decimals.");
            }
        };
    }

    /** CSV rule: clean text, no empty items, at most $max items, every item passes $item, then an optional check on the unique items. */
    private function csv(int $max, Closure $item, ?Closure $after = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($max, $item, $after) {
            $items = self::clean($value) ? self::split($value) : null;

            if ($items === null || count($items) > $max || in_array(false, array_map($item, $items), true)) {
                $fail("The {$attribute} must be a comma separated list of up to {$max} valid values.");

                return;
            }

            if ($after && ! $after(array_values(array_unique($items)))) {
                $fail("The selected {$attribute} is invalid.");
            }
        };
    }

    private function categoriesExist(array $slugs): bool
    {
        $ids = Category::whereIn('slug', $slugs)->pluck('id')->all();
        $this->categoryIds = array_map('intval', $ids);

        return count($ids) === count($slugs);
    }
}
