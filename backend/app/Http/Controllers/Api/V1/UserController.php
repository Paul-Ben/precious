<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\UserService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreStaffUserRequest;
use App\Http\Requests\Users\SyncUserRolesRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['customer', 'staff'])],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'role' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with('roles')
            ->when($filters['search'] ?? null, function ($q, string $search) {
                $term = '%'.mb_strtolower($search).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                    ->orWhere('phone', 'like', '%'.$search.'%'));
            })
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->role($role))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return ApiResponse::success(UserResource::collection($users));
    }

    public function store(StoreStaffUserRequest $request): JsonResponse
    {
        $user = $this->users->createStaff($request->validated(), $request->user());

        return ApiResponse::created(
            (new UserResource($user))->withPermissions(),
            'Staff account created. Login details have been emailed to '.$user->email.'.'
        );
    }

    public function show(User $user): JsonResponse
    {
        return ApiResponse::success((new UserResource($user->load('roles')))->withPermissions());
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->users->update($user, $request->validated(), $request->user());

        return ApiResponse::success((new UserResource($user))->withPermissions(), 'User updated.');
    }

    public function syncRoles(SyncUserRolesRequest $request, User $user): JsonResponse
    {
        $user = $this->users->syncRoles($user, $request->validated('roles'), $request->user());

        return ApiResponse::success((new UserResource($user))->withPermissions(), 'Roles updated.');
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $user = $this->users->suspend($user, $request->user(), $data['reason'] ?? null);

        return ApiResponse::success(new UserResource($user), 'User suspended and signed out.');
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $user = $this->users->activate($user, $request->user());

        return ApiResponse::success(new UserResource($user), 'User reactivated.');
    }

    public function issueTemporaryPassword(Request $request, User $user): JsonResponse
    {
        $this->users->issueTemporaryPassword($user, $request->user());

        return ApiResponse::success(null, 'A new temporary password has been emailed to '.$user->email.'.');
    }
}
