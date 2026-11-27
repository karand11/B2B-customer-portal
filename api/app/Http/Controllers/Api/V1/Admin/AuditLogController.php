<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAuditLogRequest;
use App\Http\Resources\AdminListResource;
use App\Http\Resources\AuditEntryResource;
use App\Models\AuditLogEntry;
use Illuminate\Support\Carbon;

/** Read only: there is no endpoint (and a database trigger) that edits or deletes a row. */
class AuditLogController extends Controller
{
    public function index(ListAuditLogRequest $request): AdminListResource
    {
        $this->authorize('viewAny', AuditLogEntry::class);
        $c = $request->criteria();

        $entries = AuditLogEntry::query()->with(['actor:id,name', 'company:id,name'])
            ->when($c['actor_id'], fn ($q, $id) => $q->where('actor_user_id', $id))
            ->when($c['action'], fn ($q, $actions) => $q->whereIn('action', $actions))
            ->when($c['subject_type'], fn ($q, $type) => $q->where('subject_type', $type))
            ->when($c['subject_id'], fn ($q, $id) => $q->where('subject_id', $id))
            ->when($c['company_id'], fn ($q, $id) => $q->where('company_id', $id))
            ->when($c['from'], fn ($q, $d) => $q->where('occurred_at', '>=', Carbon::parse($d, 'UTC')->startOfDay()->format('Y-m-d H:i:s')))
            ->when($c['to'], fn ($q, $d) => $q->where('occurred_at', '<', Carbon::parse($d, 'UTC')->addDay()->startOfDay()->format('Y-m-d H:i:s')))
            ->orderBy('occurred_at', $c['sort'] === 'occurred_at' ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate($c['per_page'], ['*'], 'page', $c['page']);

        return new AdminListResource($entries, AuditEntryResource::class);
    }
}
