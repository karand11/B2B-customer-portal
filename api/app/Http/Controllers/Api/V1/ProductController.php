<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListProductsRequest;
use App\Http\Resources\FiltersResource;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductListResource;
use App\Models\Product;
use App\Support\CatalogCache;
use App\Support\ProductSearch;

class ProductController extends Controller
{
    public function index(ListProductsRequest $request): ProductListResource
    {
        $c = $request->criteria();

        return new ProductListResource(ProductSearch::query($c)->paginate($c['per_page'], ['*'], 'page', $c['page']));
    }

    public function show(string $id): ProductDetailResource
    {
        $product = Product::active()
            ->select([...Product::LIST_COLUMNS, 'description'])
            ->with('category:id,slug,name')
            ->whereKey($id)
            ->firstOrFail();

        return new ProductDetailResource($product);
    }

    public function filters(): FiltersResource
    {
        return new FiltersResource(CatalogCache::filters());
    }
}
