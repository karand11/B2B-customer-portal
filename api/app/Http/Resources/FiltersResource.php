<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps the cached filter options; currency is read at request time, never cached. */
class FiltersResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['currency' => config('catalog.currency')] + $this->resource;
    }
}
