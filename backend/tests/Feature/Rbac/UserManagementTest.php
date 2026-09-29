<?php

namespace Tests\Feature\Rbac;

use App\Domain\Identity\PrivilegeGuard;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\StaffAccountCreatedNotification;
use App\Notifications\TemporaryPasswordIssuedNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    #[Test]
    public function an_admin_creates_a_staff_account_with_multiple_roles_and_a_temporary_password(): void
    {
        $admin = $this->staff('Administrator');

        $response = $this->actingAsUser($admin)->postJson('/api/v1/users', [
            'name' => 'John Doe',
            'email' => 'John.Doe@Example.com',
            'phone' => '+2348030000000',
            'roles' => ['Waiter', 'Bartender', 'Hotel Manager'],
        ])->assertCreated()
            ->assertJsonPath('data.email', 'john.doe@example.com')
            ->assertJsonPath('data.type', 'staff')
            ->assertJsonPath('data.must_change_password', true);

        $this->assertEqualsCanonicalizing(['Waiter', 'Bartender', 'Hotel Manager'], $response->json('data.roles'));

        $user = User::where('email', 'john.doe@example.com')->firstOrFail();

        Notification::assertSentTo($user, StaffAccountCreatedNotification::class, function ($n) use ($user) {
            return Hash::check($n->temporaryPassword, $user->password);
        });

        $log = AuditLog::where('action', 'users.created')->where('auditable_id', $user->id)->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
    }

    #[Test]
    public function staff_accounts_cannot_hold_the_customer_role(): void
    {
        $this->actingAsUser($this->staff('Administrator'))->postJson('/api/v1/users', [
            'name' => 'X', 'email' => 'x@example.com', 'roles' => ['Customer'],
        ])->assertStatus(422)->assertJsonPath('code', 'INVALID_ROLE');
    }

    #[Test]
    public function only_a_super_administrator_can_grant_super_administrator(): void
    {
        $admin = $this->staff('Administrator');
        $target = $this->staff('Waiter');

        $this->actingAsUser($admin)
            ->putJson("/api/v1/users/{$target->id}/roles", ['roles' => ['Super Administrator']])
            ->assertForbidden()
            ->assertJsonPath('code', 'PRIVILEGE_ESCALATION');

        $this->actingAsUser($this->staff('Super Administrator'))
            ->putJson("/api/v1/users/{$target->id}/roles", ['roles' => ['Super Administrator']])
            ->assertOk();
    }

    #[Test]
    public function users_cannot_grant_roles_with_permissions_they_do_not_have(): void
    {
        // Hotel Manager has neither roles.assign nor payments.refund by default.
        $manager = $this->staff('Hotel Manager');
        $manager->givePermissionTo('roles.assign');
        $target = $this->staff('Waiter');

        $this->actingAsUser($manager)
            ->putJson("/api/v1/users/{$target->id}/roles", ['roles' => ['Accountant']])
            ->assertForbidden()
            ->assertJsonPath('code', 'PRIVILEGE_ESCALATION');

        $this->putJson("/api/v1/users/{$target->id}/roles", ['roles' => ['Receptionist']])->assertOk();
        $this->assertTrue($target->fresh()->hasRole('Receptionist'));
    }

    #[Test]
    public function users_cannot_change_their_own_roles(): void
    {
        $admin = $this->staff('Administrator');

        $this->actingAsUser($admin)
            ->putJson("/api/v1/users/{$admin->id}/roles", ['roles' => ['Administrator', 'Accountant']])
            ->assertForbidden()
            ->assertJsonPath('code', 'SELF_ROLE_CHANGE');
    }

    #[Test]
    public function suspending_a_user_revokes_their_sessions(): void
    {
        $waiter = $this->staff('Waiter');
        $waiterToken = $this->issueToken($waiter);

        $this->actingAsUser($this->staff('Administrator'))
            ->postJson("/api/v1/users/{$waiter->id}/suspend", ['reason' => 'Left the company'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertSame(0, $waiter->tokens()->count());
        $this->withBearer($waiterToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertTrue(AuditLog::where('action', 'users.suspended')->where('auditable_id', $waiter->id)->exists());
    }

    #[Test]
    public function only_super_administrators_can_manage_super_administrators(): void
    {
        $super = $this->staff('Super Administrator');
        $other = $this->staff('Super Administrator');

        $this->actingAsUser($this->staff('Administrator'))
            ->postJson("/api/v1/users/{$super->id}/suspend")
            ->assertForbidden()
            ->assertJsonPath('code', 'PRIVILEGE_ESCALATION');

        $this->actingAsUser($other)->postJson("/api/v1/users/{$super->id}/suspend")->assertOk();
    }

    #[Test]
    public function the_last_active_super_administrator_is_protected(): void
    {
        $super = $this->staff('Super Administrator');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('last active Super Administrator');

        app(PrivilegeGuard::class)->assertNotLastSuperAdmin($super);
    }

    #[Test]
    public function nobody_can_suspend_themselves(): void
    {
        $super = $this->staff('Super Administrator');

        $this->actingAsUser($super)
            ->postJson("/api/v1/users/{$super->id}/suspend")
            ->assertForbidden()
            ->assertJsonPath('code', 'SELF_SUSPEND');
    }

    #[Test]
    public function an_admin_can_issue_a_new_temporary_password(): void
    {
        $waiter = $this->staff('Waiter');
        $this->issueToken($waiter);

        $this->actingAsUser($this->staff('Administrator'))
            ->postJson("/api/v1/users/{$waiter->id}/temporary-password")
            ->assertOk();

        $waiter->refresh();
        $this->assertTrue($waiter->must_change_password);
        $this->assertSame(0, $waiter->tokens()->count());
        Notification::assertSentTo($waiter, TemporaryPasswordIssuedNotification::class);
    }

    #[Test]
    public function users_can_be_searched_and_filtered(): void
    {
        $this->staff('Waiter')->update(['name' => 'Chidi Okafor']);
        $this->customer()->update(['name' => 'Chidinma Bello']);

        $this->actingAsUser($this->staff('Administrator'))
            ->getJson('/api/v1/users?search=chidi&type=staff')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Chidi Okafor')
            ->assertJsonStructure(['meta' => ['current_page', 'per_page', 'total', 'last_page']]);
    }
}
