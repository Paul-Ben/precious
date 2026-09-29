<?php

namespace Tests\Feature\Hotel;

use App\Domain\Reservations\AvailabilityService;
use App\Domain\Reservations\StayDates;
use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomBlock;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    private function available(string $in, string $out, int $typeId): array
    {
        return app(AvailabilityService::class)
            ->availableRooms(StayDates::fromStrings($in, $out), $typeId)
            ->pluck('number')
            ->all();
    }

    #[Test]
    public function booked_nights_are_unavailable_but_back_to_back_stays_are_allowed(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201', '202']);

        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(5), $this->day(8)))->assertCreated();

        // One of the two rooms is now held for nights 5, 6, 7.
        $this->assertCount(1, $this->available($this->day(6), $this->day(7), $type->id));
        // Arriving on the departure day is fine.
        $this->assertCount(2, $this->available($this->day(8), $this->day(10), $type->id));
        // Leaving on the arrival day is fine.
        $this->assertCount(2, $this->available($this->day(3), $this->day(5), $type->id));
    }

    #[Test]
    public function blocked_out_of_service_and_inactive_rooms_are_excluded(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201', '202', '203', '204']);

        RoomBlock::create([
            'room_id' => Room::where('number', '201')->value('id'),
            'starts_on' => $this->day(2),
            'ends_on' => $this->day(4),
            'reason' => 'MAINTENANCE',
        ]);
        Room::where('number', '202')->update(['status' => RoomStatus::OutOfService->value]);
        Room::where('number', '203')->update(['is_active' => false]);

        $this->assertSame(['204'], $this->available($this->day(3), $this->day(5), $type->id));
        $this->assertSame(['201', '204'], $this->available($this->day(4), $this->day(6), $type->id));
    }

    #[Test]
    public function the_public_availability_endpoint_returns_counts_and_server_calculated_prices(): void
    {
        $this->roomType('Deluxe', '50000.00', ['201', '202']);
        $this->roomType('Suite', '120000.00', ['401'], ['max_adults' => 3, 'max_occupancy' => 4]);

        $response = $this->getJson('/api/v1/public/availability?check_in='.$this->day(1).'&check_out='.$this->day(4).'&adults=2')
            ->assertOk()
            ->assertJsonPath('data.nights', 3)
            ->assertJsonCount(2, 'data.room_types')
            ->assertJsonPath('data.room_types.0.name', 'Deluxe')
            ->assertJsonPath('data.room_types.0.available_count', 2)
            // 3 × ₦50,000 = ₦150,000; VAT 7.5% = ₦11,250; total ₦161,250; deposit 30% = ₦48,375.
            ->assertJsonPath('data.room_types.0.quote.subtotal', '150000.00')
            ->assertJsonPath('data.room_types.0.quote.vat', '11250.00')
            ->assertJsonPath('data.room_types.0.quote.total', '161250.00')
            ->assertJsonPath('data.room_types.0.quote.deposit', '48375.00');

        $this->assertSame(1, $response->json('data.room_types.1.available_count'));
    }

    #[Test]
    public function check_out_must_be_after_check_in(): void
    {
        $this->getJson('/api/v1/public/availability?check_in='.$this->day(3).'&check_out='.$this->day(3))
            ->assertStatus(422)
            ->assertJsonValidationErrors('check_out');
    }
}
