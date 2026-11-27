<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignUserRequest;
use App\Http\Requests\Admin\InviteUserRequest;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\AdminListResource;
use App\Http\Resources\UserAdminResource;
use App\Models\User;
use App\Services\UserAdmin;
use App\Services\UserInvitations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin only (role:admin on the routes, UserPolicy here). Row and sort whitelists live in ListUsersRequest. */
class UserController extends Controller
{
    public function index(ListUsersRequest $request): AdminListResource
    {
        $this->authorize('viewAny', User::class);
        $c = $request->criteria();

        $users = User::query()->with(['roles', 'company:id,name'])
            ->when($c['q'], fn ($q, $term) => $q->where(fn ($w) => $w
                ->whereRaw('users.name ilike ?', ['%'.addcslashes($term, '%_\\').'%'])
                ->orWhereRaw('users.email ilike ?', ['%'.addcslashes($term, '%_\\').'%'])))
            ->when($c['role'], fn ($q, $roles) => $q->whereHas('roles', fn ($r) => $r->whereIn('name', $roles)))
            ->when($c['status'], fn ($q, $statuses) => $q->where(fn ($w) => array_map(fn ($s) => $w->orWhereRaw(match ($s) {
                'inactive' => 'not users.active',
                'pending' => '(users.active and users.invited_at is not null and users.invitation_accepted_at is null)',
                'active' => '(users.active and not (users.invited_at is not null and users.invitation_accepted_at is null))',
            }), $statuses)))
            ->when($c['company_id'], fn ($q, $id) => $q->where('users.company_id', $id))
            ->orderBy('users.'.ltrim($c['sort'], '-'), str_starts_with($c['sort'], '-') ? 'desc' : 'asc')
            ->orderBy('users.id')
            ->paginate($c['per_page'], ['users.*'], 'page', $c['page']);

        return new AdminListResource($users, UserAdminResource::class);
    }

    public function show(User $user): UserAdminResource
    {
        $this->authorize('view', $user);

        return $this->respond($user);
    }

    public function store(InviteUserRequest $request, UserInvitations $invitations): JsonResponse
    {
        $this->authorize('create', User::class);
        [$user, $queued] = $invitations->invite($request->user(), $request->invitation());

        return $this->respond($user)->additional(['meta' => ['email_queued' => $queued]])->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user, UserAdmin $users): UserAdminResource
    {
        $this->authorize('update', $user);

        return $this->respond($users->rename($request->user(), $user, $request->name()));
    }

    public function assign(AssignUserRequest $request, User $user, UserAdmin $users): UserAdminResource
    {
        $this->authorize('assign', $user);

        return $this->respond($users->assign($request->user(), $user, $request->role(), $request->companyId()));
    }

    public function deactivate(Request $request, User $user, UserAdmin $users): UserAdminResource
    {
        $this->authorize('deactivate', $user);

        return $this->respond($users->deactivate($request->user(), $user));
    }

    public function reactivate(Request $request, User $user, UserAdmin $users): UserAdminResource
    {
        $this->authorize('reactivate', $user);

        return $this->respond($users->reactivate($request->user(), $user));
    }

    public function passwordReset(Request $request, User $user, UserAdmin $users): JsonResponse
    {
        $this->authorize('resetPassword', $user);
        $users->requestPasswordReset($request->user(), $user);

        return response()->json(['message' => 'A password reset email has been queued.']);
    }

    /** The two relations the resource reads (a detail is 3 queries including the lookup). */
    private function respond(User $user): UserAdminResource
    {
        return new UserAdminResource($user->load(['roles', 'company:id,name']));
    }
}
