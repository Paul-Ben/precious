<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Department;
use App\Models\ExpenseCategory;
use App\Models\Property;
use App\Models\ShiftTemplate;
use Illuminate\Database\Seeder;

/**
 * The single hotel property, its departments and the standard amenity list.
 * Idempotent; never overwrites details an administrator has edited.
 */
class PropertySeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::query()->firstOrCreate(
            ['slug' => 'precious-hotel'],
            [
                'name' => 'Precious Hotel & Bar',
                'country' => 'NG',
                'timezone' => 'Africa/Lagos',
                'currency' => 'NGN',
            ]
        );

        $departments = [
            'MGMT' => 'Management',
            'FRONT' => 'Front Office',
            'HOUSE' => 'Housekeeping',
            'BAR' => 'Bar',
            'KITCHEN' => 'Kitchen & Room Service',
            'FIN' => 'Finance',
            'MAINT' => 'Maintenance',
            'SEC' => 'Security',
        ];

        foreach ($departments as $code => $name) {
            Department::query()->firstOrCreate(
                ['property_id' => $property->id, 'code' => $code],
                ['name' => $name]
            );
        }

        // P24 – standard shifts; managers can add others or enter custom times.
        foreach ([['Morning', '07:00', '15:00'], ['Afternoon', '15:00', '23:00'], ['Night', '23:00', '07:00']] as [$name, $start, $end]) {
            ShiftTemplate::query()->firstOrCreate(
                ['property_id' => $property->id, 'name' => $name],
                ['start_time' => $start, 'end_time' => $end]
            );
        }

        foreach (config('hotel.expense_categories') as $name) {
            ExpenseCategory::query()->firstOrCreate(['property_id' => $property->id, 'name' => $name]);
        }

        $amenities = [
            'Wi-Fi' => 'wifi', 'Air conditioning' => 'snowflake', 'Smart TV' => 'tv',
            'Mini bar' => 'wine', 'Work desk' => 'lamp-desk', 'Rain shower' => 'shower-head',
            'Bathtub' => 'bath', 'Safe' => 'lock', 'Kettle' => 'coffee', 'City view' => 'building-2',
            'Balcony' => 'sun', 'Living room' => 'sofa', 'Lounge access' => 'armchair',
            'Room service' => 'concierge-bell', 'Breakfast option' => 'croissant',
        ];

        $order = 0;
        foreach ($amenities as $name => $icon) {
            Amenity::query()->firstOrCreate(['name' => $name], ['icon' => $icon, 'sort_order' => $order++]);
        }

        Property::forgetCurrent();
    }
}
