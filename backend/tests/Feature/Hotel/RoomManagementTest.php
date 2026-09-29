<?php

namespace Tests\Feature\Hotel;

use App\Models\Amenity;
use App\Models\AuditLog;
use App\Models\Room;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoomManagementTest extends TestCase
{
    #[Test]
    public function a_manager_sets_up_room_types_and_rooms(): void
    {
        $this->actingAsUser($this->staff('Hotel Manager'));

        $typeId = $this->postJson('/api/v1/room-types', [
            'name' => 'Deluxe King',
            'base_rate' => '50000',
            'max_adults' => 2,
            'max_occupancy' => 3,
            'amenity_ids' => Amenity::whereIn('name', ['Wi-Fi', 'Smart TV'])->pluck('id')->all(),
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'deluxe-king')
            ->assertJsonPath('data.base_rate', '50000.00')
            ->assertJsonCount(2, 'data.amenities')
            ->json('data.id');

        $this->postJson('/api/v1/rooms', ['room_type_id' => $typeId, 'number' => '201', 'floor' => '2'])->assertCreated()->assertJsonPath('data.status', 'AVAILABLE');
        $this->postJson('/api/v1/rooms', ['room_type_id' => $typeId, 'number' => '201'])->assertStatus(422)->assertJsonValidationErrors('number');

        $this->getJson('/api/v1/room-types')->assertOk()->assertJsonPath('data.0.rooms_count', 1);
        $this->assertTrue(AuditLog::where('action', 'room_types.created')->exists());
    }

    #[Test]
    public function room_status_changes_are_permission_checked_and_audited(): void
    {
        $this->roomType('Deluxe', '50000.00', ['201']);
        $room = Room::firstOrFail();

        $this->actingAsUser($this->staff('Receptionist'))
            ->patchJson("/api/v1/rooms/{$room->id}/status", ['status' => 'DIRTY'])
            ->assertOk()
            ->assertJsonPath('data.status', 'DIRTY');

        // Receptionists can change status but cannot create rooms.
        $this->postJson('/api/v1/rooms', ['room_type_id' => $room->room_type_id, 'number' => '999'])->assertForbidden();

        $log = AuditLog::where('action', 'rooms.status_changed')->firstOrFail();
        $this->assertSame(['status' => 'AVAILABLE'], $log->old_values);
        $this->assertSame(['status' => 'DIRTY'], $log->new_values);

        $this->actingAsUser($this->staff('Waiter'))
            ->patchJson("/api/v1/rooms/{$room->id}/status", ['status' => 'AVAILABLE'])
            ->assertForbidden();
    }

    #[Test]
    public function a_room_cannot_be_blocked_over_existing_bookings(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);
        $room = Room::firstOrFail();
        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(5), $this->day(7)))->assertCreated();

        $this->actingAsUser($this->staff('Hotel Manager'));

        $this->postJson("/api/v1/rooms/{$room->id}/blocks", [
            'starts_on' => $this->day(6), 'ends_on' => $this->day(9), 'reason' => 'MAINTENANCE',
        ])->assertStatus(409)->assertJsonPath('code', 'ROOM_HAS_BOOKINGS');

        $blockId = $this->postJson("/api/v1/rooms/{$room->id}/blocks", [
            'starts_on' => $this->day(7), 'ends_on' => $this->day(9), 'reason' => 'MAINTENANCE', 'notes' => 'Repaint',
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/rooms/{$room->id}/blocks/{$blockId}")->assertOk();
    }

    #[Test]
    public function rooms_with_booking_history_cannot_be_deleted(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201', '202']);
        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(1), $this->day(2)))->assertCreated();

        $this->actingAsUser($this->staff('Hotel Manager'));
        $booked = Room::whereHas('reservationRooms')->firstOrFail();
        $free = Room::whereDoesntHave('reservationRooms')->firstOrFail();

        $this->deleteJson("/api/v1/rooms/{$booked->id}")->assertStatus(422)->assertJsonPath('code', 'ROOM_IN_USE');
        $this->deleteJson("/api/v1/rooms/{$free->id}")->assertOk();
        $this->deleteJson("/api/v1/room-types/{$type->id}")->assertStatus(422)->assertJsonPath('code', 'ROOM_TYPE_IN_USE');
    }

    #[Test]
    public function room_type_photos_are_validated_and_stored_on_the_media_disk(): void
    {
        Storage::fake('public');
        $type = $this->roomType('Deluxe', '50000.00', []);
        $this->actingAsUser($this->staff('Hotel Manager'));

        $this->postJson("/api/v1/room-types/{$type->id}/images", [
            'image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');

        $response = $this->postJson("/api/v1/room-types/{$type->id}/images", [
            'image' => UploadedFile::fake()->image('room.jpg', 1200, 800),
            'alt' => 'Deluxe room with king bed',
        ])->assertCreated()->assertJsonCount(1, 'data.images');

        $this->assertNotNull($response->json('data.cover_image_url'));
        $this->assertCount(1, Storage::disk('public')->allFiles("room-types/{$type->id}"));
    }

    #[Test]
    public function inactive_room_types_are_hidden_from_the_public_site(): void
    {
        $this->roomType('Deluxe', '50000.00', ['201']);
        $this->roomType('Hidden', '10000.00', ['901'], ['is_active' => false]);

        $this->getJson('/api/v1/public/room-types')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Deluxe');
        $this->getJson('/api/v1/public/room-types/hidden')->assertNotFound();
        $this->getJson('/api/v1/public/room-types/deluxe')->assertOk();
    }
}
