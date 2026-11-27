<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApplyProductImportRequest;
use App\Http\Resources\ProductImportResource;
use App\Models\Product;
use App\Models\ProductImport;
use App\Services\ProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductImportController extends Controller
{
    public function preview(Request $request, ProductImportService $imports): JsonResponse
    {
        $this->authorize('import', Product::class);

        return (new ProductImportResource($imports->preview($request->user(), $request->file('file'))))->response()->setStatusCode(201);
    }

    /** `{import}` is bound in routes/api.php to the CALLER's own imports: another user's token, an unknown one and a malformed one are all 404. */
    public function apply(ApplyProductImportRequest $request, ProductImport $import, ProductImportService $imports): JsonResponse
    {
        $this->authorize('import', Product::class);

        $applied = $imports->apply($request->user(), $import->id, $request->skipInvalid());

        return response()->json(['data' => [
            'id' => $applied->id,
            'status' => $applied->status,
            'rows_read' => $applied->rows_read,
            'created' => $applied->result['created'],
            'updated' => $applied->result['updated'],
            'bad_rows' => $applied->bad_rows,
        ]]);
    }
}
