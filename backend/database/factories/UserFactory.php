<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Password used by every factory user: satisfies the password policy. */
    public const PASSWORD = 'Secret-Passw0rd!';

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'phone' => '+23480'.fake()->unique()->numerify('########'),
            'type' => UserType::Customer,
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(self::PASSWORD),
            'must_change_password' => false,
            'two_factor_enabled' => false,
            'remember_token' => Str::random(10),
        ];
    }

    public function staff(): static
    {
        return $this->state(fn () => ['type' => UserType::Staff]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }

    /**
     * Staff member holding the given role(s).
     */
    public function withRoles(string ...$roles): static
    {
        return $this->staff()->afterCreating(fn (User $user) => $user->syncRoles($roles));
    }
}
