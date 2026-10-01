<?php

namespace App\Domain\Identity;

/**
 * The single source of truth for permission names.
 *
 * Permissions are defined in code (because code checks them) and synced into
 * the database by PermissionSeeder. Roles are configurable at runtime.
 */
final class PermissionCatalog
{
    /**
     * @return array<string, array<string, string>> group => [permission => description]
     */
    public static function grouped(): array
    {
        return [
            'users' => [
                'users.view' => 'View user accounts',
                'users.create' => 'Create staff accounts',
                'users.update' => 'Update, suspend and reactivate user accounts',
                'users.delete' => 'Delete user accounts',
            ],
            'roles' => [
                'roles.view' => 'View roles and permissions',
                'roles.create' => 'Create roles',
                'roles.update' => 'Edit roles and their permissions',
                'roles.delete' => 'Delete custom roles',
                'roles.assign' => 'Assign roles to users',
            ],
            'rooms' => [
                'rooms.view' => 'View rooms and room types',
                'rooms.create' => 'Create rooms and room types',
                'rooms.update' => 'Edit rooms and room types',
                'rooms.manage_status' => 'Change room status',
            ],
            'guests' => [
                'guests.view' => 'View guest records',
                'guests.create' => 'Create guest records',
                'guests.update' => 'Edit guest records',
                'guests.documents.view' => 'View guest identity documents',
            ],
            'reservations' => [
                'reservations.view' => 'View reservations',
                'reservations.create' => 'Create reservations',
                'reservations.update' => 'Edit reservations',
                'reservations.cancel' => 'Cancel reservations',
                'checkins.create' => 'Check guests in',
                'checkouts.create' => 'Check guests out',
                'checkouts.override_balance' => 'Check a guest out with an unpaid balance (P18)',
            ],
            'services' => [
                'services.view' => 'View hotel services',
                'services.manage' => 'Create and edit hotel services',
                'services.charge' => 'Add service charges to a guest bill',
            ],
            'bar' => [
                'bar.tables.view' => 'View bar tables',
                'bar.tables.manage' => 'Create and edit bar tables',
                'bar.products.view' => 'View bar products',
                'bar.products.manage' => 'Create and edit bar products',
                'bar.orders.create' => 'Create bar orders',
                'bar.orders.view' => 'View bar orders',
                'bar.orders.update' => 'Edit bar orders',
                'bar.orders.prepare' => 'Accept and prepare bar orders',
                'bar.orders.deliver' => 'Mark bar orders delivered',
                'bar.orders.cancel' => 'Cancel bar orders',
                'bar.orders.charge_to_room' => 'Charge bar orders to a hotel room',
            ],
            'billing' => [
                'bills.view' => 'View bills',
                'bills.update' => 'Add or adjust bill items',
                'discounts.apply' => 'Apply discounts',
                'discounts.approve' => 'Approve discounts',
            ],
            'payments' => [
                'payments.view' => 'View payments',
                'payments.create' => 'Record payments (including manual cash/POS/transfer)',
                'payments.refund' => 'Issue refunds',
            ],
            'finance' => [
                'finance.view' => 'View finance dashboard',
                'finance.reports' => 'View financial reports',
                'finance.expenses' => 'Record and manage expenses',
                'finance.expenses.approve' => 'Approve or reject expenses above the approval limit',
                'finance.close_day' => 'Close the day (daily closing and cash count)',
                'finance.reopen_day' => 'Reopen a closed day',
            ],
            'staff' => [
                'staff.view' => 'View staff records',
                'staff.create' => 'Create staff records',
                'staff.update' => 'Edit staff records',
                'staff.schedule' => 'Manage shifts and attendance',
            ],
            'inventory' => [
                'inventory.view' => 'View inventory',
                'inventory.manage' => 'Manage stock, suppliers and purchases',
            ],
            'reports' => [
                'reports.view' => 'View operational reports',
                'reports.export' => 'Export reports',
            ],
            'audit' => [
                'audit.view' => 'View the audit log',
            ],
            'settings' => [
                'settings.view' => 'View system settings',
                'settings.update' => 'Change system settings',
                'settings.payment_gateways.manage' => 'Configure payment gateways and API keys',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::grouped())));
    }

    public static function groupOf(string $permission): ?string
    {
        foreach (self::grouped() as $group => $permissions) {
            if (array_key_exists($permission, $permissions)) {
                return $group;
            }
        }

        return null;
    }
}
