<?php

namespace Database\Seeders;

use App\Domain\Identity\RoleDefaults;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the first Super Administrator from SUPER_ADMIN_* env variables.
 * The account must change its password at first login and always uses 2FA.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('security.bootstrap_admin');
        $email = Str::lower(trim((string) ($config['email'] ?? '')));

        if ($email === '') {
            $this->command?->warn('SUPER_ADMIN_EMAIL is not set - skipping super administrator.');

            return;
        }

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Super administrator {$email} already exists.");

            return;
        }

        $password = (string) ($config['password'] ?? '');
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(16);
        }

        $user = User::create([
            'name' => $config['name'] ?: 'System Administrator',
            'email' => $email,
            'password' => $password,
            'type' => UserType::Staff,
            'status' => UserStatus::Active,
            'must_change_password' => true,
            'two_factor_enabled' => true,
            'email_verified_at' => now(),
        ]);

        $user->assignRole(RoleDefaults::SUPER_ADMIN);

        $this->command?->info("Super administrator created: {$email}");

        if ($generated) {
            $this->command?->warn("Generated temporary password: {$password}");
            $this->command?->warn('Store it safely - it will not be shown again. You must change it on first login.');
        }
    }
}
