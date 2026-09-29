<?php

namespace App\Domain\Identity;

/**
 * Default (system) roles and their initial permissions. Managers can change
 * permissions later from the UI; re-running the seeder only creates missing
 * roles and never overwrites edited permissions (unless --force is used).
 */
final class RoleDefaults
{
    public const SUPER_ADMIN = 'Super Administrator';

    public const ADMIN = 'Administrator';

    public const CUSTOMER = 'Customer';

    /**
     * @return array<string, array{description: string, permissions: list<string>|string}>
     */
    public static function definitions(): array
    {
        return [
            self::SUPER_ADMIN => [
                'description' => 'Unrestricted access to everything, including other administrators.',
                'permissions' => '*',
            ],
            self::ADMIN => [
                'description' => 'Full operational and configuration access.',
                'permissions' => '*',
            ],
            'Hotel Manager' => [
                'description' => 'Runs day-to-day hotel and bar operations.',
                'permissions' => [
                    'users.view',
                    'roles.view',
                    'rooms.view', 'rooms.create', 'rooms.update', 'rooms.manage_status',
                    'guests.view', 'guests.create', 'guests.update', 'guests.documents.view',
                    'reservations.view', 'reservations.create', 'reservations.update', 'reservations.cancel',
                    'checkins.create', 'checkouts.create',
                    'services.view', 'services.manage', 'services.charge',
                    'bar.tables.view', 'bar.tables.manage', 'bar.products.view', 'bar.products.manage',
                    'bar.orders.view', 'bar.orders.cancel', 'bar.orders.charge_to_room',
                    'bills.view', 'bills.update', 'discounts.apply', 'discounts.approve',
                    'payments.view', 'payments.create',
                    'finance.view', 'finance.reports',
                    'staff.view', 'staff.create', 'staff.update', 'staff.schedule',
                    'inventory.view',
                    'reports.view', 'reports.export',
                    'settings.view',
                ],
            ],
            'Receptionist' => [
                'description' => 'Front desk: reservations, check-in/out and guest bills.',
                'permissions' => [
                    'rooms.view', 'rooms.manage_status',
                    'guests.view', 'guests.create', 'guests.update', 'guests.documents.view',
                    'reservations.view', 'reservations.create', 'reservations.update', 'reservations.cancel',
                    'checkins.create', 'checkouts.create',
                    'services.view', 'services.charge',
                    'bills.view', 'bills.update',
                    'payments.view', 'payments.create',
                ],
            ],
            'Accountant' => [
                'description' => 'Payments, refunds, expenses and financial reporting.',
                'permissions' => [
                    'bills.view',
                    'payments.view', 'payments.create', 'payments.refund',
                    'finance.view', 'finance.reports', 'finance.expenses',
                    'reports.view', 'reports.export',
                ],
            ],
            'Waiter' => [
                'description' => 'Takes bar orders at tables and settles bills.',
                'permissions' => [
                    'bar.tables.view', 'bar.products.view',
                    'bar.orders.create', 'bar.orders.view', 'bar.orders.update', 'bar.orders.deliver',
                    'bar.orders.charge_to_room',
                    'bills.view', 'payments.create',
                ],
            ],
            'Service Agent' => [
                'description' => 'Takes orders and adds hotel service charges.',
                'permissions' => [
                    'bar.tables.view', 'bar.products.view',
                    'bar.orders.create', 'bar.orders.view', 'bar.orders.update', 'bar.orders.deliver',
                    'services.view', 'services.charge',
                    'bills.view', 'payments.create',
                ],
            ],
            'Bartender' => [
                'description' => 'Prepares bar orders from the live queue.',
                'permissions' => [
                    'bar.products.view', 'bar.orders.view', 'bar.orders.prepare',
                ],
            ],
            'Inventory Manager' => [
                'description' => 'Stock, suppliers and purchases.',
                'permissions' => [
                    'bar.products.view', 'bar.products.manage',
                    'inventory.view', 'inventory.manage',
                    'reports.view',
                ],
            ],
            'Auditor' => [
                'description' => 'Read-only access to records, reports and the audit log.',
                'permissions' => [
                    'users.view', 'roles.view',
                    'rooms.view', 'guests.view', 'reservations.view',
                    'services.view', 'bar.orders.view', 'bills.view',
                    'payments.view', 'finance.view', 'finance.reports',
                    'staff.view', 'inventory.view',
                    'reports.view', 'reports.export',
                    'audit.view',
                ],
            ],
            self::CUSTOMER => [
                'description' => 'Hotel guest / customer account. Access is limited to their own records.',
                'permissions' => [],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        $permissions = self::definitions()[$role]['permissions'] ?? [];

        return $permissions === '*' ? PermissionCatalog::all() : $permissions;
    }
}
