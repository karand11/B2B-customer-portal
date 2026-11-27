<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListProductsRequest;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Http\Resources\AdminListResource;
use App\Http\Resources\AdminProductResource;
use App\Models\Product;
use App\Services\ProductAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(ListProductsRequest $request): AdminListResource
    {
        $this->authorize('viewAny', Product::class);
        $c = $request->criteria();

        return new AdminListResource(Product::adminList($c)->paginate($c['per_page'], ['*'], 'page', $c['page']), AdminProductResource::class);
    }

    public function show(Product $product): AdminProductResource
    {
        $this->authorize('view', $product);

        return new AdminProductResource($product->load('category:id,slug,name'));
    }

    public function store(StoreProductRequest $request, ProductAdmin $products): JsonResponse
    {
        $this->authorize('create', Product::class);

        return (new AdminProductResource($products->create($request->user(), $request->productAttributes(), $request->active())))
            ->response()->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product, ProductAdmin $products): AdminProductResource
    {
        $this->authorize('update', $product);

        return new AdminProductResource($products->update($request->user(), $product, $request->productAttributes(), $request->version()));
    }

    public function activate(Request $request, Product $product, ProductAdmin $products): AdminProductResource
    {
        $this->authorize('activate', $product);

        return new AdminProductResource($products->setActive($request->user(), $product, true));
    }

    public function deactivate(Request $request, Product $product, ProductAdmin $products): AdminProductResource
    {
        $this->authorize('activate', $product);

        return new AdminProductResource($products->setActive($request->user(), $product, false));
    }
}
