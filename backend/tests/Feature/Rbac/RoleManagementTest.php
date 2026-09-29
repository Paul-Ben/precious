<?php

namespace Tests\Feature\Rbac;

use App\Models\AuditLog;
use App\Models\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    #[Test]
    public function roles_and_the_permission_catalog_can_be_listed(): void
    {
        $this->actingAsUser($this->staff('Administrator'))
            ->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonCount(11, 'data');

        $this->getJson('/api/v1/permissions')
            ->assertOk()
            ->assertJsonFragment(['name' => 'bar.orders.charge_to_room']);
    }

    #[Test]
    public function a_custom_role_can_be_created_and_its_permissions_changed(): void
    {
        $this->actingAsUser($this->staff('Administrator'));

        $id = $this->postJson('/api/v1/roles', [
            'name' => 'Night Auditor',
            'description' => 'Overnight checks',
            'permissions' => ['reports.view', 'payments.view'],
        ])->assertCreated()
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.permissions', ['payments.view', 'reports.view'])
            ->json('data.id');

        $this->putJson("/api/v1/roles/{$id}/permissions", ['permissions' => ['reports.view', 'audit.view']])
            ->assertOk()
            ->assertJsonPath('data.permissions', ['audit.view', 'reports.view']);

        $log = AuditLog::where('action', 'roles.permissions_synced')->where('auditable_id', (string) $id)->firstOrFail();
        $this->assertSame(['added' => ['audit.view']], $log->new_values);
        $this->assertSame(['removed' => ['payments.view']], $log->old_values);
    }

    #[Test]
    public function unknown_permissions_are_rejected(): void
    {
        $this->actingAsUser($this->staff('Administrator'))
            ->postJson('/api/v1/roles', ['name' => 'Bad', 'permissions' => ['everything.all']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions.0');
    }

    #[Test]
    public function system_roles_cannot_be_deleted_or_renamed(): void
    {
        $role = Role::findByName('Waiter', 'web');
        $this->actingAsUser($this->staff('Administrator'));

        $this->deleteJson("/api/v1/roles/{$role->id}")->assertStatus(422)->assertJsonPath('code', 'SYSTEM_ROLE');
        $this->patchJson("/api/v1/roles/{$role->id}", ['name' => 'Server'])->assertStatus(422);
        $this->patchJson("/api/v1/roles/{$role->id}", ['description' => 'Serves tables'])->assertOk();
    }

    #[Test]
    public function a_role_in_use_cannot_be_deleted(): void
    {
        $role = Role::create(['name' => 'Temp', 'guard_name' => 'web']);
        $this->staff('Temp');

        $this->actingAsUser($this->staff('Administrator'))
            ->deleteJson("/api/v1/roles/{$role->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ROLE_IN_USE');
    }

    #[Test]
    public function the_super_administrator_role_cannot_be_edited(): void
    {
        $role = Role::findByName('Super Administrator', 'web');

        $this->actingAsUser($this->staff('Super Administrator'))
            ->putJson("/api/v1/roles/{$role->id}/permissions", ['permissions' => []])
            ->assertStatus(422);
    }

    #[Test]
    public function non_super_admins_cannot_grant_permissions_they_lack(): void
    {
        $manager = $this->staff('Hotel Manager');
        $manager->givePermissionTo('roles.create');

        $this->actingAsUser($manager)
            ->postJson('/api/v1/roles', ['name' => 'Refunder', 'permissions' => ['payments.refund']])
            ->assertForbidden()
            ->assertJsonPath('code', 'PRIVILEGE_ESCALATION');
    }
}
