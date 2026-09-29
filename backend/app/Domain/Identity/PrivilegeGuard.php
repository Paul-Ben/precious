<?php

namespace App\Domain\Identity;

use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Prevents privilege escalation: a non-super-admin can only grant roles or
 * permissions that they themselves hold.
 */
class PrivilegeGuard
{
    /**
     * @param  iterable<string>  $permissions
     */
    public function assertCanGrantPermissions(User $actor, iterable $permissions): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $own = $actor->getAllPermissions()->pluck('name');
        $missing = collect($permissions)->diff($own)->values();

        if ($missing->isNotEmpty()) {
            throw new BusinessRuleException(
                'You cannot grant permissions that you do not have yourself.',
                'PRIVILEGE_ESCALATION',
                403,
                ['permissions' => $missing->all()]
            );
        }
    }

    /**
     * @param  Collection<int, Role>  $roles
     */
    public function assertCanGrantRoles(User $actor, Collection $roles): void
    {
        if ($roles->contains(fn (Role $r) => $r->name === RoleDefaults::SUPER_ADMIN) && ! $actor->isSuperAdmin()) {
            throw new BusinessRuleException(
                'Only a Super Administrator can grant the Super Administrator role.',
                'PRIVILEGE_ESCALATION',
                403
            );
        }

        $this->assertCanGrantPermissions(
            $actor,
            $roles->flatMap(fn (Role $r) => $r->permissions->pluck('name'))->unique()
        );
    }

    public function assertCanManage(User $actor, User $target): void
    {
        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw new BusinessRuleException(
                'Only a Super Administrator can manage another Super Administrator.',
                'PRIVILEGE_ESCALATION',
                403
            );
        }
    }

    public function assertNotLastSuperAdmin(User $target): void
    {
        if (! $target->isSuperAdmin()) {
            return;
        }

        $activeSuperAdmins = User::role(RoleDefaults::SUPER_ADMIN)
            ->where('status', 'active')
            ->count();

        if ($activeSuperAdmins <= 1) {
            throw new BusinessRuleException(
                'This is the last active Super Administrator and cannot be removed or suspended.',
                'LAST_SUPER_ADMIN',
                422
            );
        }
    }
}
