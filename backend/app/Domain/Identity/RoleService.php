<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class RoleService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PrivilegeGuard $guard,
    ) {}

    /**
     * @param  array{name: string, description?: ?string, permissions?: list<string>}  $data
     */
    public function create(array $data, User $actor): Role
    {
        $permissions = $data['permissions'] ?? [];
        $this->guard->assertCanGrantPermissions($actor, $permissions);

        return DB::transaction(function () use ($data, $permissions) {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'web',
                'description' => $data['description'] ?? null,
                'is_system' => false,
            ]);

            $role->syncPermissions($permissions);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->audit->record('roles.created', $role, new: [
                'name' => $role->name,
                'description' => $role->description,
                'permissions' => array_values($permissions),
            ]);

            return $role->load('permissions');
        });
    }

    /**
     * @param  array{name?: string, description?: ?string}  $data
     */
    public function update(Role $role, array $data): Role
    {
        if ($role->is_system && isset($data['name']) && $data['name'] !== $role->name) {
            throw new BusinessRuleException('System roles cannot be renamed.', 'SYSTEM_ROLE', 422);
        }

        return DB::transaction(function () use ($role, $data) {
            $before = $role->only(['name', 'description']);
            $role->fill($data)->save();

            [$old, $new] = $this->audit->diff($before, $role->only(['name', 'description']));

            if ($new !== []) {
                $this->audit->record('roles.updated', $role, $old, $new);
            }

            return $role->load('permissions');
        });
    }

    /**
     * @param  list<string>  $permissions
     */
    public function syncPermissions(Role $role, array $permissions, User $actor): Role
    {
        if ($role->name === RoleDefaults::SUPER_ADMIN) {
            throw new BusinessRuleException('The Super Administrator role always has every permission.', 'SYSTEM_ROLE', 422);
        }

        if ($role->name === RoleDefaults::CUSTOMER && $permissions !== []) {
            throw new BusinessRuleException('The Customer role cannot be given staff permissions.', 'SYSTEM_ROLE', 422);
        }

        if ($actor->hasRole($role->name) && ! $actor->isSuperAdmin()) {
            throw new BusinessRuleException('You cannot change the permissions of a role you hold.', 'SELF_ROLE_CHANGE', 403);
        }

        $current = $role->permissions->pluck('name');
        $added = collect($permissions)->diff($current)->values();
        $removed = $current->diff($permissions)->values();

        // Both granting and taking away require that the actor holds the permission.
        $this->guard->assertCanGrantPermissions($actor, $added->merge($removed));

        return DB::transaction(function () use ($role, $permissions, $added, $removed) {
            $role->syncPermissions($permissions);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->audit->record('roles.permissions_synced', $role,
                ['removed' => $removed->all()],
                ['added' => $added->all()],
            );

            return $role->load('permissions');
        });
    }

    public function delete(Role $role): void
    {
        if ($role->is_system) {
            throw new BusinessRuleException('System roles cannot be deleted.', 'SYSTEM_ROLE', 422);
        }

        $assigned = User::role($role->name)->count();

        if ($assigned > 0) {
            throw new BusinessRuleException(
                "This role is assigned to {$assigned} user(s). Reassign them before deleting it.",
                'ROLE_IN_USE',
                422
            );
        }

        DB::transaction(function () use ($role) {
            $snapshot = ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()];
            $role->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->audit->record('roles.deleted', $role, $snapshot, null);
        });
    }
}
