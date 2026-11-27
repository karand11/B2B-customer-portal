<?php

namespace App\Http\Requests\Admin;

/** POST /admin/products. `active` is optional (default true) and only accepted on creation. */
class StoreProductRequest extends ProductWriteRequest
{
    protected function extraRules(): array
    {
        return ['active' => ['bail', 'nullable', 'boolean']];
    }

    public function active(): bool
    {
        return (bool) ($this->validated('active') ?? true);
    }
}
