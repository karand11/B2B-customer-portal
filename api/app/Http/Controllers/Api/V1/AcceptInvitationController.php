<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptInvitationRequest;
use App\Services\UserInvitations;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AcceptInvitationController extends Controller
{
    public function __invoke(AcceptInvitationRequest $request, UserInvitations $invitations): JsonResponse
    {
        // Unknown, wrong, used, expired and revoked tokens and not-pending users all answer the same 422 (no enumeration).
        if (! $invitations->accept($request->only('email', 'password', 'password_confirmation', 'token'))) {
            throw ValidationException::withMessages(['email' => ['This invitation link is invalid or has expired.']]);
        }

        return response()->json(['message' => 'Your account is ready. You can sign in now.']);
    }
}
