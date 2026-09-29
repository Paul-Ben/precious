<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditService;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /** Used to keep timing similar when the account does not exist. */
    private const DUMMY_HASH = '$2y$12$7tt5GkYEtMG6rQGGDndKbuM/RwXMrc3RRxp0BTKYFxl/5mRt4p1KC';

    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{name: string, email: string, phone?: ?string, password: string}  $data
     * @return array{user: User, token: array}
     */
    public function registerCustomer(array $data): array
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'type' => UserType::Customer,
                'status' => UserStatus::Active,
                'password_changed_at' => now(),
            ]);

            $user->assignRole(RoleDefaults::CUSTOMER);

            $this->audit->record('auth.registered', $user, new: [
                'name' => $user->name,
                'email' => $user->email,
            ], actor: $user);

            return $user;
        });

        return ['user' => $user, 'token' => $this->tokens->issue($user, false)];
    }

    /**
     * Validates credentials. Returns either a token or a pending 2FA challenge.
     *
     * @return array{user: User, token?: array, two_factor?: array}
     */
    public function login(string $email, string $password, UserType $expectedType): array
    {
        $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->where('type', $expectedType->value)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            if (! $user) {
                Hash::check($password, self::DUMMY_HASH);
            }

            $this->audit->record('auth.login_failed', $user, metadata: [
                'email' => mb_strtolower(trim($email)),
                'portal' => $expectedType->value,
            ], actor: $user);

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->isActive()) {
            $this->audit->record('auth.login_blocked', $user, metadata: ['reason' => 'suspended'], actor: $user);

            throw new BusinessRuleException('Your account has been suspended. Please contact an administrator.', 'ACCOUNT_SUSPENDED', 403);
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->saveQuietly();
        }

        if ($user->requiresTwoFactor()) {
            return ['user' => $user, 'two_factor' => $this->twoFactor->createChallenge($user)];
        }

        $token = $this->tokens->issue($user, false);
        $this->audit->record('auth.login', $user, metadata: ['portal' => $expectedType->value, 'two_factor' => false], actor: $user);

        return ['user' => $user, 'token' => $token];
    }

    /**
     * @return array{user: User, token: array}
     */
    public function completeTwoFactorLogin(string $challengeId, string $code): array
    {
        $user = $this->twoFactor->verify($challengeId, $code);
        $token = $this->tokens->issue($user, true);

        $this->audit->record('auth.login', $user, metadata: ['portal' => $user->type->value, 'two_factor' => true], actor: $user);

        return ['user' => $user, 'token' => $token];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
        $this->audit->record('auth.logout', $user, actor: $user);
    }

    public function changePassword(User $user, string $current, string $new): void
    {
        if (! Hash::check($current, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        if (Hash::check($new, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The new password must be different from the current password.'],
            ]);
        }

        DB::transaction(function () use ($user, $new) {
            $user->forceFill([
                'password' => $new,
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();

            // Sign out every other session.
            $currentId = $user->currentAccessToken()?->getKey();
            $user->tokens()->when($currentId, fn ($q) => $q->whereKeyNot($currentId))->delete();

            $this->audit->record('auth.password_changed', $user, actor: $user);
        });
    }
}
