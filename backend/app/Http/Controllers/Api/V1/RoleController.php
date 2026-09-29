<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\PermissionCatalog;
use App\Domain\Identity\RoleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\SyncRolePermissionsRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->with('permissions')
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(RoleResource::collection($roles));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($request->validated(), $request->user());

        return ApiResponse::created(new RoleResource($role), 'Role created.');
    }

    public function show(Role $role): JsonResponse
    {
        return ApiResponse::success(new RoleResource($role->load('permissions')->loadCount('users')));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->roles->update($role, $request->validated());

        return ApiResponse::success(new RoleResource($role), 'Role updated.');
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $role = $this->roles->syncPermissions($role->load('permissions'), $request->validated('permissions'), $request->user());

        return ApiResponse::success(new RoleResource($role), 'Permissions updated.');
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->roles->delete($role->load('permissions'));

        return ApiResponse::success(null, 'Role deleted.');
    }

    /**
     * GET /api/v1/permissions - the permission catalog grouped by module.
     */
    public function permissions(Request $request): JsonResponse
    {
        $groups = collect(PermissionCatalog::grouped())->map(fn (array $permissions, string $group) => [
            'group' => $group,
            'permissions' => collect($permissions)->map(fn (string $description, string $name) => [
                'name' => $name,
                'description' => $description,
            ])->values(),
        ])->values();

        return ApiResponse::success($groups);
    }
}
