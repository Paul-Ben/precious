<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\BarCategory;
use App\Models\BarProduct;
use App\Models\BarTable;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * SAMPLE room types and rooms for local development and demos only.
 * Run with: php artisan db:seed --class=DemoHotelSeeder
 * Never run in production.
 */
class DemoHotelSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoHotelSeeder is for local/demo use only.');

            return;
        }

        $property = Property::current();

        $types = [
            ['Standard', '35000.00', 2, 0, 2, 'Queen', 22, ['Wi-Fi', 'Air conditioning', 'Smart TV', 'Kettle'], ['101', '102', '103', '104']],
            ['Deluxe', '50000.00', 2, 1, 3, 'King', 28, ['Wi-Fi', 'Air conditioning', 'Smart TV', 'Rain shower', 'City view', 'Breakfast option'], ['201', '202', '203', '204', '205']],
            ['Executive', '75000.00', 2, 1, 3, 'King', 34, ['Wi-Fi', 'Air conditioning', 'Smart TV', 'Work desk', 'Mini bar', 'Lounge access'], ['301', '302', '303']],
            ['Suite', '120000.00', 3, 2, 4, 'King + sofa bed', 55, ['Wi-Fi', 'Air conditioning', 'Smart TV', 'Living room', 'Bathtub', 'Mini bar', 'Room service'], ['401', '402']],
            ['Presidential', '250000.00', 4, 2, 6, '2 × King', 110, ['Wi-Fi', 'Air conditioning', 'Smart TV', 'Living room', 'Bathtub', 'Balcony', 'Lounge access', 'Room service'], ['501']],
        ];

        foreach ($types as $i => [$name, $rate, $adults, $children, $occupancy, $bed, $size, $amenities, $numbers]) {
            $type = RoomType::query()->firstOrCreate(
                ['property_id' => $property->id, 'slug' => str($name)->slug()->toString()],
                [
                    'name' => $name,
                    'short_description' => "[SAMPLE] {$name} room — replace with real copy.",
                    'base_rate' => $rate,
                    'max_adults' => $adults,
                    'max_children' => $children,
                    'max_occupancy' => $occupancy,
                    'bed_type' => $bed,
                    'size_sqm' => $size,
                    'sort_order' => $i,
                ]
            );

            $type->amenities()->syncWithoutDetaching(Amenity::query()->whereIn('name', $amenities)->pluck('id'));

            foreach ($numbers as $number) {
                Room::query()->firstOrCreate(
                    ['property_id' => $property->id, 'number' => $number],
                    ['room_type_id' => $type->id, 'floor' => (string) intdiv((int) $number, 100)]
                );
            }
        }

        // SAMPLE services and prices - replace with the hotel's real price list.
        $services = [
            ['Laundry (per item)', 'Laundry', '1500.00'],
            ['Pressing (per item)', 'Laundry', '800.00'],
            ['Room service delivery', 'Room Service', '1000.00'],
            ['Extra bed (per night)', 'Extra Bed', '10000.00'],
            ['Airport transfer', 'Transport', '25000.00'],
            ['Meeting room (half day)', 'Conference', '60000.00'],
        ];

        foreach ($services as $i => [$name, $category, $price]) {
            Service::withTrashed()->firstOrCreate(
                ['property_id' => $property->id, 'name' => $name],
                ['category' => $category, 'price' => $price, 'sort_order' => $i]
            );
        }

        // SAMPLE bar menu and tables - replace with the real menu and prices.
        $menu = [
            'Beer' => [['Star Lager', '1500.00'], ['Heineken', '2000.00'], ['Guinness Stout', '2000.00']],
            'Cocktails' => [['Mojito', '6000.00'], ['Chapman', '4000.00'], ['Pina Colada', '6500.00']],
            'Soft drinks' => [['Coca-Cola', '1000.00'], ['Bottled water', '800.00'], ['Fresh orange juice', '2500.00']],
            'Wine & spirits' => [['House red (glass)', '5000.00'], ['Hennessy VS (shot)', '7000.00']],
            'Small chops' => [['Peppered gizzard', '4500.00'], ['Suya platter', '6000.00'], ['Spring rolls (6)', '3500.00']],
        ];

        $i = 0;

        foreach ($menu as $categoryName => $products) {
            $category = BarCategory::query()->firstOrCreate(['property_id' => $property->id, 'name' => $categoryName], ['sort_order' => $i++]);

            foreach ($products as $j => [$name, $price]) {
                BarProduct::withTrashed()->firstOrCreate(
                    ['property_id' => $property->id, 'name' => $name],
                    ['category_id' => $category->id, 'price' => $price, 'sort_order' => $j]
                );
            }
        }

        foreach (range(1, 8) as $n) {
            BarTable::query()->firstOrCreate(
                ['property_id' => $property->id, 'name' => 'Table '.$n],
                ['capacity' => $n <= 4 ? 4 : 6, 'area' => $n <= 5 ? 'Lounge' : 'Terrace', 'sort_order' => $n]
            );
        }

        $this->command?->info('Sample room types, rooms, services, bar menu and tables created.');
    }
}
