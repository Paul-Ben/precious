<?php

namespace Database\Seeders;

use App\Domain\Identity\RoleDefaults;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the system roles. Existing roles keep their (possibly edited)
 * permissions, except "Super Administrator" and "Administrator" which always
 * receive every permission so new permissions reach them automatically.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleDefaults::definitions() as $name => $definition) {
            $role = Role::query()->firstOrNew(['name' => $name, 'guard_name' => 'web']);
            $isNew = ! $role->exists;

            $role->fill([
                'description' => $role->description ?? $definition['description'],
                'is_system' => true,
            ])->save();

            if ($isNew || $definition['permissions'] === '*') {
                $role->syncPermissions(RoleDefaults::permissionsFor($name));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
