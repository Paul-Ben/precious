<?php

namespace Tests\Unit;

use App\Domain\Identity\PermissionCatalog;
use App\Domain\Identity\RoleDefaults;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PermissionCatalogTest extends TestCase
{
    #[Test]
    public function permission_names_are_unique(): void
    {
        $all = PermissionCatalog::all();

        $this->assertSame(count($all), count(array_unique($all)));
    }

    #[Test]
    public function the_catalog_contains_every_permission_named_in_the_specification(): void
    {
        $spec = [
            'users.view', 'users.create', 'users.update', 'users.delete',
            'roles.view', 'roles.create', 'roles.update', 'roles.assign',
            'rooms.view', 'rooms.create', 'rooms.update', 'rooms.manage_status',
            'reservations.view', 'reservations.create', 'reservations.update', 'reservations.cancel',
            'checkins.create', 'checkouts.create',
            'bar.orders.create', 'bar.orders.view', 'bar.orders.update', 'bar.orders.prepare', 'bar.orders.deliver', 'bar.orders.cancel',
            'payments.view', 'payments.create', 'payments.refund',
            'finance.view', 'finance.reports', 'finance.expenses',
            'staff.view', 'staff.create', 'staff.update', 'staff.schedule',
            'inventory.view', 'inventory.manage',
            'reports.view', 'reports.export',
            'audit.view',
            'discounts.apply', 'discounts.approve',
        ];

        $this->assertSame([], array_values(array_diff($spec, PermissionCatalog::all())));
    }

    #[Test]
    public function default_roles_only_reference_existing_permissions(): void
    {
        foreach (array_keys(RoleDefaults::definitions()) as $role) {
            $unknown = array_diff(RoleDefaults::permissionsFor($role), PermissionCatalog::all());

            $this->assertSame([], array_values($unknown), "Role {$role} references unknown permissions.");
        }
    }

    #[Test]
    public function the_eleven_roles_from_the_specification_exist(): void
    {
        $this->assertEqualsCanonicalizing([
            'Super Administrator', 'Administrator', 'Hotel Manager', 'Receptionist', 'Accountant',
            'Waiter', 'Service Agent', 'Bartender', 'Inventory Manager', 'Auditor', 'Customer',
        ], array_keys(RoleDefaults::definitions()));
    }
}
