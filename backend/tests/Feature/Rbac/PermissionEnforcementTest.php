<?php

namespace Tests\Feature\Rbac;

use App\Models\Role;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionEnforcementTest extends TestCase
{
    #[Test]
    public function staff_without_the_permission_are_forbidden(): void
    {
        $this->actingAsUser($this->staff('Waiter'))
            ->getJson('/api/v1/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    #[Test]
    public function customers_cannot_reach_staff_endpoints(): void
    {
        $this->actingAsUser($this->customer())
            ->getJson('/api/v1/roles')
            ->assertForbidden();
    }

    #[Test]
    public function a_user_with_multiple_roles_gets_the_union_of_their_permissions(): void
    {
        $user = $this->staff('Waiter', 'Bartender', 'Auditor');

        $response = $this->actingAsUser($user)->getJson('/api/v1/auth/me')->assertOk();

        $permissions = $response->json('data.permissions');
        $this->assertContains('bar.orders.create', $permissions);   // Waiter
        $this->assertContains('bar.orders.prepare', $permissions);  // Bartender
        $this->assertContains('audit.view', $permissions);          // Auditor
        $this->assertEqualsCanonicalizing(['Waiter', 'Bartender', 'Auditor'], $response->json('data.roles'));

        // Auditor grants users.view, so this now works.
        $this->getJson('/api/v1/users')->assertOk();
        // But nobody granted users.create.
        $this->postJson('/api/v1/users', [])->assertForbidden();
    }

    #[Test]
    public function removing_a_permission_restricts_access_immediately(): void
    {
        $auditor = $this->staff('Auditor');
        $this->actingAsUser($auditor)->getJson('/api/v1/audit-logs')->assertOk();

        Role::findByName('Auditor', 'web')->revokePermissionTo('audit.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    #[Test]
    public function removing_a_role_restricts_access_immediately(): void
    {
        $user = $this->staff('Auditor', 'Waiter');
        $this->actingAsUser($user)->getJson('/api/v1/audit-logs')->assertOk();

        $user->removeRole('Auditor');
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    #[Test]
    public function super_administrators_pass_every_permission_check(): void
    {
        $super = $this->staff('Super Administrator');

        $this->actingAsUser($super)->getJson('/api/v1/users')->assertOk();
        $this->getJson('/api/v1/audit-logs')->assertOk();
        $this->getJson('/api/v1/settings/payment-gateways')->assertOk();
    }
}
