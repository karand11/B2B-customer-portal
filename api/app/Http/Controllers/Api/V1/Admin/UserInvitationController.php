<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserAdminResource;
use App\Models\User;
use App\Services\UserInvitations;
use Illuminate\Http\Request;

class UserInvitationController extends Controller
{
    /** Resend (also re-invites a revoked user): a new token, the old one stops working. */
    public function resend(Request $request, User $user, UserInvitations $invitations): UserAdminResource
    {
        $this->authorize('manageInvitation', $user);
        [$resent, $queued] = $invitations->resend($request->user(), $user);

        return $this->respond($resent)->additional(['meta' => ['email_queued' => $queued]]);
    }

    public function revoke(Request $request, User $user, UserInvitations $invitations): UserAdminResource
    {
        $this->authorize('manageInvitation', $user);

        return $this->respond($invitations->revoke($request->user(), $user));
    }

    private function respond(User $user): UserAdminResource
    {
        return new UserAdminResource($user->load(['roles', 'company:id,name']));
    }
}
