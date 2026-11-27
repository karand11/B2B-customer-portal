<?php

namespace App\Http\Resources;

use App\Models\AuditLogEntry;
use App\Support\AuditRedactor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLogEntry Needs `actor` and `company` loaded. `changes` and `metadata` pass through the allowlist AGAIN on the way out. */
class AuditEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $changes = AuditRedactor::changes($this->subject_type, $this->changes ?? []);
        $metadata = AuditRedactor::filter($this->subject_type, $this->metadata ?? [], AuditRedactor::METADATA);

        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'action' => $this->action,
            'actor' => $this->actor_user_id === null ? null : ['id' => $this->actor_user_id, 'name' => $this->actor->name, 'role' => $this->actor_role],
            'subject' => ['type' => $this->subject_type, 'id' => $this->subject_id, 'label' => $this->subject_label],
            'company' => $this->company_id === null ? null : ['id' => $this->company->id, 'name' => $this->company->name],
            // An empty PHP array would encode as [] : the contract says objects.
            'changes' => $changes === [] ? (object) [] : array_map(fn ($side) => (object) $side, $changes),
            'metadata' => (object) $metadata,
        ];
    }
}
