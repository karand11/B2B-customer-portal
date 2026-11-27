<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use App\Support\InputRules;
use App\Support\PartNumber;
use App\Support\ProductRules;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/** Fields and rules shared by POST and PUT /admin/products (the CSV importer uses the same patterns through ProductRules). */
abstract class ProductWriteRequest extends AdminWriteRequest
{
    /** The product being replaced (PUT), so its own part number is not "in use". */
    protected function ignoreId(): ?int
    {
        return null;
    }

    protected function prepareForValidation(): void
    {
        // validationData() is the body bag ($this->request), so that is the bag to normalise (merge() would write to another one for JSON).
        // bodyInput(), not InputBag::get(): get() throws (a 400) when the value is an array.
        $part = $this->bodyInput('part_number');
        $description = $this->bodyInput('description');

        is_string($part) && $this->request->set('part_number', strtoupper(trim($part)));
        is_string($description) && $this->request->set('description', str_replace("\r\n", "\n", $description));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $text = fn (int $max) => ['bail', 'required', 'string', 'min:1', "max:{$max}", InputRules::line()];
        $materialLike = ['bail', 'required', 'string', 'min:1', 'max:64', fn (string $a, mixed $v, Closure $fail) => preg_match(ProductRules::TEXT_PATTERN, $v) === 1
            ? null : $fail("The {$a} may only contain letters, digits, space . & / + - and must start with a letter or digit.")];

        return [
            'category_id' => ['bail', 'required', InputRules::int(1, PHP_INT_MAX), Rule::exists('categories', 'id')],
            'part_number' => ['bail', 'required', 'string', 'max:64', $this->partNumberRule()],
            'name' => [...$text(255), ProductRules::noFormula()],
            'description' => ['bail', 'nullable', 'string', 'max:5000', $this->multiline(), ProductRules::noFormula()],
            'brand' => $materialLike,
            'material' => $materialLike,
            'inner_diameter_mm' => ['bail', 'required', ProductRules::mm()],
            'outer_diameter_mm' => ['bail', 'required', ProductRules::mm()],
            'width_mm' => ['bail', 'required', ProductRules::mm()],
            'seal_type' => ['bail', 'required', 'string', Rule::in(Product::SEAL_TYPES)],
            'stock_status' => ['bail', 'required', 'string', Rule::in(Product::STOCK_STATUSES)],
            'price_on_request' => ['bail', 'required', 'boolean'],
            'price_cents' => ['bail', 'nullable', InputRules::int(0, Product::MAX_PRICE_CENTS)],
        ] + $this->extraRules();
    }

    /** @return array<string, array<int, mixed>> */
    protected function extraRules(): array
    {
        return [];
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $errors = $validator->errors();
            $v = $this->request->all();

            if (! $errors->hasAny(['inner_diameter_mm', 'outer_diameter_mm'])
                && (float) ProductRules::mmValue($v['outer_diameter_mm'] ?? 0) <= (float) ProductRules::mmValue($v['inner_diameter_mm'] ?? 0)) {
                $errors->add('outer_diameter_mm', 'The outer_diameter_mm must be greater than inner_diameter_mm.');
            }

            if ($errors->hasAny(['price_on_request', 'price_cents'])) {
                return;
            }

            $onRequest = filter_var($v['price_on_request'], FILTER_VALIDATE_BOOLEAN);
            $price = $v['price_cents'] ?? null;

            if ($onRequest && $price !== null) {
                $errors->add('price_cents', 'The price_cents must be empty when price_on_request is true.');
            } elseif (! $onRequest && $price === null) {
                $errors->add('price_cents', 'The price_cents is required unless price_on_request is true.');
            }
        }];
    }

    /**
     * The product columns, server-built from validated input only (never request()->all(), never `active` or `version`).
     *
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        $v = $this->validated();
        $onRequest = (bool) $v['price_on_request'];

        return [
            'category_id' => $v['category_id'],
            'part_number' => $v['part_number'],
            'name' => $v['name'],
            'description' => ($v['description'] ?? '') === '' ? null : $v['description'],
            'brand' => $v['brand'],
            'inner_diameter_mm' => ProductRules::mmValue($v['inner_diameter_mm']),
            'outer_diameter_mm' => ProductRules::mmValue($v['outer_diameter_mm']),
            'width_mm' => ProductRules::mmValue($v['width_mm']),
            'material' => $v['material'],
            'seal_type' => $v['seal_type'],
            'price_cents' => $onRequest ? null : $v['price_cents'],
            'price_on_request' => $onRequest,
            'stock_status' => $v['stock_status'],
        ];
    }

    private function partNumberRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (preg_match(ProductRules::PART_NUMBER_PATTERN, $value) !== 1) {
                $fail('The part number must start with a letter or digit and may only contain letters, digits, space . _ / -.');

                return;
            }

            $key = PartNumber::normalise($value);
            if ($key === '') {
                $fail('The part number must contain a letter or digit.');
            } elseif (Product::where('part_number_search', $key)->when($this->ignoreId(), fn ($q, $id) => $q->where('id', '<>', $id))->exists()) {
                $fail('This part number is already in use.');
            }
        };
    }

    /** Description: LF and tab are allowed (CRLF was normalised before validation), every other control or format character is not. */
    private function multiline(): Closure
    {
        return fn (string $attribute, mixed $value, Closure $fail) => is_string($value) && (! mb_check_encoding($value, 'UTF-8') || preg_match('/(?![\t\n])\p{Cc}|\p{Cf}/u', $value))
            ? $fail("The {$attribute} contains invalid characters.") : null;
    }
}
