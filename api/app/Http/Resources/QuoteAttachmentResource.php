<?php

namespace App\Http\Resources;

use App\Models\QuoteAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public uuid only (never the numeric id or the storage path). Needs uploadedBy loaded. The name is display-only text.
 * A customer viewer does not get staff names.
 *
 * @mixin QuoteAttachment
 */
class QuoteAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hide = ! $request->user()->isStaff() && $this->uploaded_by_role === 'staff';

        return [
            'id' => $this->public_id,
            'name' => $this->original_name,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'uploaded_by' => ['name' => $hide ? null : $this->uploadedBy->name, 'role' => $this->uploaded_by_role],
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
