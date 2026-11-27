<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User Exactly these keys: never a password, remember_token, session_epoch or token.
 * Needs `roles` and `company` loaded (list and detail queries do).
 */
class UserAdminResource extends JsonResource
{
    private const FORMAT = 'Y-m-d\TH:i:s\Z';

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->primaryRole(),
            'company' => $this->company_id ? ['id' => $this->company->id, 'name' => $this->company->name] : null,
            'status' => $this->status(),
            'invited_at' => $this->invited_at?->utc()->format(self::FORMAT),
            'invitation_accepted_at' => $this->invitation_accepted_at?->utc()->format(self::FORMAT),
            'created_at' => $this->created_at->utc()->format(self::FORMAT),
            'allowed_actions' => $this->allowedActions($request->user()),
        ];
    }

    /**
     * Computed for the calling admin and this row. The list does not pre-check the last-admin rule (a query per row): the server refuses it.
     *
     * @return list<string>
     */
    private function allowedActions(User $viewer): array
    {
        if ($viewer->is($this->resource)) {
            return ['edit'];
        }

        $status = $this->status();
        $actions = ['edit', 'assign'];

        $status === 'active' && array_push($actions, 'deactivate', 'reset_password');
        $status === 'inactive' && ! $this->hasOpenInvitation() && $actions[] = 'reactivate';
        $this->hasOpenInvitation() && $actions[] = 'resend_invitation';
        $status === 'pending' && $actions[] = 'revoke_invitation';

        return $actions;
    }
}
