<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Reference data required by every environment (including tests):
 * permissions, system roles, payment gateway rows and the hotel property.
 */
class CoreSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            PaymentGatewaySeeder::class,
            PropertySeeder::class,
        ]);
    }
}
