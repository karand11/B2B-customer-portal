<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Support\Mm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'part_number' => $this->part_number,
            'name' => $this->name,
            'brand' => $this->brand,
            'category' => ['id' => $this->category->id, 'slug' => $this->category->slug, 'name' => $this->category->name],
            'inner_diameter_mm' => Mm::number($this->inner_diameter_mm),
            'outer_diameter_mm' => Mm::number($this->outer_diameter_mm),
            'width_mm' => Mm::number($this->width_mm),
            'material' => $this->material,
            'seal_type' => $this->seal_type,
            'price_cents' => $this->price_cents,
            'price_on_request' => $this->price_on_request,
            'stock_status' => $this->stock_status,
        ];
    }
}
