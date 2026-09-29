<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditService;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffAccountCreatedNotification;
use App\Notifications\TemporaryPasswordIssuedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class UserService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PrivilegeGuard $guard,
    ) {}

    /**
     * Creates a staff account with a temporary password that must be changed
     * on first login. The password is emailed to the new user.
     *
     * @param  array{name: string, email: string, phone?: ?string, roles: list<string>, two_factor_enabled?: bool}  $data
     */
    public function createStaff(array $data, User $actor): User
    {
        $roles = $this->resolveStaffRoles($data['roles']);
        $this->guard->assertCanGrantRoles($actor, $roles);

        $temporaryPassword = self::temporaryPassword();

        $user = DB::transaction(function () use ($data, $roles, $temporaryPassword) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $temporaryPassword,
                'type' => UserType::Staff,
                'status' => UserStatus::Active,
                'must_change_password' => true,
                'two_factor_enabled' => (bool) ($data['two_factor_enabled'] ?? false),
            ]);

            $user->syncRoles($roles);

            $this->audit->record('users.created', $user, new: [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'type' => $user->type->value,
                'roles' => $roles->pluck('name')->all(),
            ]);

            return $user;
        });

        // Sent immediately so the plain-text password is never written to the queue.
        $user->notifyNow(new StaffAccountCreatedNotification($temporaryPassword));

        return $user->load('roles');
    }

    /**
     * @param  array{name?: string, email?: string, phone?: ?string, two_factor_enabled?: bool}  $data
     */
    public function update(User $user, array $data, User $actor): User
    {
        $this->guard->assertCanManage($actor, $user);

        $before = $user->only(array_keys($data));

        DB::transaction(function () use ($user, $data, $before) {
            $user->fill($data)->save();

            [$old, $new] = $this->audit->diff($before, $user->only(array_keys($data)));

            if ($new !== []) {
                $this->audit->record('users.updated', $user, $old, $new);
            }
        });

        return $user->refresh()->load('roles');
    }

    /**
     * @param  list<string>  $roleNames
     */
    public function syncRoles(User $user, array $roleNames, User $actor): User
    {
        if ($user->is($actor)) {
            throw new BusinessRuleException('You cannot change your own roles.', 'SELF_ROLE_CHANGE', 403);
        }

        $this->guard->assertCanManage($actor, $user);

        $roles = $user->isStaff()
            ? $this->resolveStaffRoles($roleNames)
            : $this->resolveCustomerRoles($roleNames);

        $currentNames = $user->roles->pluck('name');
        $added = $roles->pluck('name')->diff($currentNames);
        $removed = $currentNames->diff($roles->pluck('name'));

        // Granting or removing Super Administrator both require a Super Administrator.
        $this->guard->assertCanGrantRoles($actor, $roles->whereIn('name', $added));

        if ($removed->contains(RoleDefaults::SUPER_ADMIN)) {
            $this->guard->assertCanGrantRoles($actor, Role::where('name', RoleDefaults::SUPER_ADMIN)->get());
            $this->guard->assertNotLastSuperAdmin($user);
        }

        DB::transaction(function () use ($user, $roles, $currentNames) {
            $user->syncRoles($roles);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $user->unsetRelation('roles')->unsetRelation('permissions');

            // A user who now requires 2FA must sign in again through the 2FA flow.
            if ($user->requiresTwoFactor()) {
                $user->tokens()->where('two_factor_confirmed', false)->delete();
            }

            $this->audit->record('users.roles_synced', $user,
                ['roles' => $currentNames->values()->all()],
                ['roles' => $roles->pluck('name')->values()->all()]
            );
        });

        return $user->refresh()->load('roles');
    }

    public function suspend(User $user, User $actor, ?string $reason = null): User
    {
        if ($user->is($actor)) {
            throw new BusinessRuleException('You cannot suspend your own account.', 'SELF_SUSPEND', 403);
        }

        $this->guard->assertCanManage($actor, $user);
        $this->guard->assertNotLastSuperAdmin($user);

        DB::transaction(function () use ($user, $reason) {
            $user->forceFill(['status' => UserStatus::Suspended])->save();
            $user->tokens()->delete();

            $this->audit->record('users.suspended', $user,
                ['status' => UserStatus::Active->value],
                ['status' => UserStatus::Suspended->value],
                ['reason' => $reason]
            );
        });

        return $user->refresh()->load('roles');
    }

    public function activate(User $user, User $actor): User
    {
        $this->guard->assertCanManage($actor, $user);

        DB::transaction(function () use ($user) {
            $user->forceFill(['status' => UserStatus::Active])->save();

            $this->audit->record('users.activated', $user,
                ['status' => UserStatus::Suspended->value],
                ['status' => UserStatus::Active->value]
            );
        });

        return $user->refresh()->load('roles');
    }

    /**
     * Issues a new temporary password (e.g. the staff member forgot theirs).
     */
    public function issueTemporaryPassword(User $user, User $actor): void
    {
        if (! $user->isStaff()) {
            throw new BusinessRuleException('Customers reset their own password by email.', 'NOT_STAFF', 422);
        }

        $this->guard->assertCanManage($actor, $user);

        $temporaryPassword = self::temporaryPassword();

        DB::transaction(function () use ($user, $temporaryPassword) {
            $user->forceFill([
                'password' => $temporaryPassword,
                'must_change_password' => true,
            ])->save();

            $user->tokens()->delete();

            $this->audit->record('users.temporary_password_issued', $user);
        });

        $user->notifyNow(new TemporaryPasswordIssuedNotification($temporaryPassword));
    }

    public static function temporaryPassword(): string
    {
        // Guaranteed to satisfy the password policy (upper, lower, digit, symbol).
        return Str::password(10, symbols: false).'Aa1!'.Str::password(4, symbols: false);
    }

    /**
     * @param  list<string>  $names
     * @return Collection<int, Role>
     */
    private function resolveStaffRoles(array $names): Collection
    {
        $roles = Role::with('permissions')->whereIn('name', $names)->where('guard_name', 'web')->get();

        if ($roles->count() !== count(array_unique($names))) {
            throw new BusinessRuleException('One or more roles do not exist.', 'UNKNOWN_ROLE', 422);
        }

        if ($roles->contains('name', RoleDefaults::CUSTOMER)) {
            throw new BusinessRuleException('Staff accounts cannot hold the Customer role.', 'INVALID_ROLE', 422);
        }

        if ($roles->isEmpty()) {
            throw new BusinessRuleException('A staff account needs at least one role.', 'ROLE_REQUIRED', 422);
        }

        return $roles;
    }

    /**
     * @param  list<string>  $names
     * @return Collection<int, Role>
     */
    private function resolveCustomerRoles(array $names): Collection
    {
        if (array_values(array_unique($names)) !== [RoleDefaults::CUSTOMER]) {
            throw new BusinessRuleException('Customer accounts can only hold the Customer role.', 'INVALID_ROLE', 422);
        }

        return Role::with('permissions')->where('name', RoleDefaults::CUSTOMER)->get();
    }
}
