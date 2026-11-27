<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Support\PartNumber;

/** GET /admin/products: all products, active and inactive. */
class ListProductsRequest extends AdminListRequest
{
    private const SORTS = ['part_number', 'name', 'brand', 'price', 'updated_at'];

    /** @var list<int> */
    private array $categoryIds = [];

    protected function sorts(): array
    {
        return [...self::SORTS, ...array_map(fn ($s) => "-{$s}", self::SORTS)];
    }

    public function rules(): array
    {
        return [
            'q' => $this->qRules(),
            'active' => ['bail', 'nullable', 'string', 'in:true,false'],
            'category' => ['bail', 'nullable', 'string', $this->csvRule(10, fn ($i) => preg_match('/^[a-z0-9-]{1,64}$/D', $i) === 1), $this->categoriesExist()],
            'brand' => ['bail', 'nullable', 'string', $this->csvRule(20, fn ($i) => mb_strlen($i) <= 64)],
            'stock_status' => ['bail', 'nullable', 'string', $this->inList(Product::STOCK_STATUSES, 4)],
        ] + $this->pagingRules();
    }

    /** @return array<string, mixed> */
    public function criteria(): array
    {
        $active = $this->validated('active');

        return [
            // null = no search; '' = a search that normalised to nothing (matches no rows).
            'q' => ($q = $this->q()) === null ? null : PartNumber::normalise($q),
            'active' => $active === null ? null : $active === 'true',
            'category_ids' => $this->categoryIds,
            'brand' => $this->csv('brand'),
            'stock_status' => $this->csv('stock_status'),
            'sort' => $this->sort(),
            'page' => $this->page(),
            'per_page' => $this->perPage(),
        ];
    }

    private function categoriesExist(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $slugs = array_values(array_unique(self::split($value) ?? []));
            $ids = Category::whereIn('slug', $slugs)->pluck('id')->all();
            $this->categoryIds = array_map('intval', $ids);

            count($ids) === count($slugs) || $fail("The selected {$attribute} is invalid.");
        };
    }
}
