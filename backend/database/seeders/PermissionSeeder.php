<?php

namespace Database\Seeders;

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Syncs the permission catalog (code) into the database. Idempotent.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::grouped() as $group => $permissions) {
            foreach ($permissions as $name => $description) {
                Permission::query()->updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['group' => $group, 'description' => $description],
                );
            }
        }

        // Remove permissions that no longer exist in the catalog.
        Permission::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', PermissionCatalog::all())
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
