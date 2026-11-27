<?php

namespace App\Http\Resources;

use App\Models\ProductImport;
use App\Services\ProductImporter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductImport The preview (step 1) form; apply answers with a smaller body (see ProductImportController). */
class ProductImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file_name' => $this->original_name,
            'size_bytes' => $this->size_bytes,
            'status' => $this->status,
            'expires_at' => $this->expires_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'rows_read' => $this->rows_read,
            'would_create' => $this->would_create,
            'would_update' => $this->would_update,
            'bad_rows' => $this->bad_rows,
            'errors' => $this->errors,
            'errors_truncated' => $this->bad_rows > ProductImporter::MAX_LISTED,
            'warnings' => $this->warnings,
        ];
    }
}
