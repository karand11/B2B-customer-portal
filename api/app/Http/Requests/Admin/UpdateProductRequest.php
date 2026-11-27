<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use App\Support\InputRules;

/** PUT /admin/products/{product}: full replacement plus the `version` the client loaded. `active` is never accepted here. */
class UpdateProductRequest extends ProductWriteRequest
{
    protected function ignoreId(): ?int
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product->id : null;
    }

    protected function extraRules(): array
    {
        return ['version' => ['bail', 'required', InputRules::int(1, PHP_INT_MAX)]];
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }
}
