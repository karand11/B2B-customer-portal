<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/** Emits exactly `data` and `meta` (six keys), no `links`. Wraps a LengthAwarePaginator and renders every row with $item. */
class AdminListResource extends JsonResource
{
    public static $wrap = null;

    /** @param  class-string<JsonResource>  $item */
    public function __construct(LengthAwarePaginator $page, private readonly string $item)
    {
        parent::__construct($page);
    }

    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator $page */
        $page = $this->resource;

        return [
            'data' => ($this->item)::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ];
    }
}
