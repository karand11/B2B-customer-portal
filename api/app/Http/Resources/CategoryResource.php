<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps the cached array {id, slug, name, product_count}. */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'slug' => $this['slug'],
            'name' => $this['name'],
            'product_count' => $this['product_count'],
        ];
    }
}
