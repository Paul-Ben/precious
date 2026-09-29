<?php

namespace Tests\Feature\Stays;

use App\Models\AuditLog;
use App\Models\Room;
use App\Models\Stay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A fixed morning in hotel time keeps "today" and late fees predictable.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Africa/Lagos'));
    }

    #[Test]
    public function a_confirmed_guest_is_checked_in_on_the_arrival_day(): void
    {
        $reservation = $this->confirmedBooking($this->roomType('Deluxe', '50000.00', ['201']), fromDay: 0, fullyPaid: false);
        $receptionist = $this->staff('Receptionist');

        $this->actingAsUser($receptionist)
            ->getJson("/api/v1/reservations/{$reservation->id}/check-in")
            ->assertOk()
            ->assertJsonPath('data.can_check_in', true)
            ->assertJsonPath('data.id_required', true)
            ->assertJsonPath('data.rooms.0.current.number', '201');

        $this->postJson("/api/v1/reservations/{$reservation->id}/check-in", ['id_type' => 'PASSPORT', 'id_number' => 'A01234567'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CHECKED_IN')
            ->assertJsonPath('data.stays.0.status', 'OPEN')
            ->assertJsonPath('data.stays.0.room.number', '201')
            ->assertJsonPath('data.stays.0.id_number_masked', '•••••4567');

        $this->assertSame('OCCUPIED', Room::where('number', '201')->first()->status->value);
        $stay = Stay::firstOrFail();
        $this->assertSame('A01234567', $stay->id_number);
        $this->assertNotSame('A01234567', DB::table('stays')->value('id_number')); // encrypted at rest
        $this->assertTrue(AuditLog::where('action', 'reservations.checked_in')->exists());
    }

    #[Test]
    public function p8_check_in_needs_the_deposit(): void
    {
        [$reservation] = $this->bookOnline($this->roomType(), 0);

        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/reservations/{$reservation->id}/check-in", ['id_type' => 'NATIONAL_ID', 'id_number' => '1'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DEPOSIT_REQUIRED');
    }

    #[Test]
    public function check_in_is_only_possible_from_the_arrival_day(): void
    {
        $reservation = $this->confirmedBooking($this->roomType(), fromDay: 3);

        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/reservations/{$reservation->id}/check-in", ['id_type' => 'NATIONAL_ID', 'id_number' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'NOT_ARRIVAL_DAY');
    }

    #[Test]
    public function p15_an_id_must_be_recorded_unless_the_policy_is_off(): void
    {
        $reservation = $this->confirmedBooking($this->roomType('Deluxe', '50000.00', ['201', '202']));
        $admin = $this->staff('Administrator');

        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/reservations/{$reservation->id}/check-in")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ID_REQUIRED');

        $this->actingAsUser($admin)->patchJson('/api/v1/property', ['policies' => ['require_id_at_check_in' => false]])->assertOk();

        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/reservations/{$reservation->id}/check-in")
            ->assertOk();
    }

    #[Test]
    public function a_room_that_is_not_clean_cannot_be_given_but_another_can(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201', '202']);
        $reservation = $this->confirmedBooking($type);
        $booked = $reservation->rooms()->first()->room;
        $booked->forceFill(['status' => 'DIRTY'])->save();
        $other = Room::where('number', $booked->number === '201' ? '202' : '201')->firstOrFail();
        $line = $reservation->rooms()->first();

        $this->actingAsUser($this->staff('Receptionist'));
        $options = $this->getJson("/api/v1/reservations/{$reservation->id}/check-in")->assertOk();
        $this->assertFalse($options->json('data.rooms.0.current.ready'));
        $this->assertSame($other->number, $options->json('data.rooms.0.alternatives.0.number'));

        $this->postJson("/api/v1/reservations/{$reservation->id}/check-in", ['id_type' => 'NATIONAL_ID', 'id_number' => '1'])
            ->assertStatus(409)->assertJsonPath('code', 'ROOM_NOT_READY');

        $this->postJson("/api/v1/reservations/{$reservation->id}/check-in", [
            'id_type' => 'NATIONAL_ID',
            'id_number' => '1',
            'rooms' => [['reservation_room_id' => $line->id, 'room_id' => $other->id]],
        ])->assertOk()->assertJsonPath('data.stays.0.room.number', $other->number);

        $this->assertSame($other->id, $line->refresh()->room_id);
    }

    #[Test]
    public function only_staff_with_check_in_permission_can_check_in(): void
    {
        $reservation = $this->confirmedBooking($this->roomType());

        $this->actingAsUser($this->staff('Bartender'))
            ->postJson("/api/v1/reservations/{$reservation->id}/check-in", ['id_type' => 'NATIONAL_ID', 'id_number' => '1'])
            ->assertForbidden();
    }

    #[Test]
    public function in_house_guests_are_listed(): void
    {
        $this->checkIn($this->confirmedBooking($this->roomType('Deluxe', '50000.00', ['201'])));

        $this->actingAsUser($this->staff('Receptionist'))
            ->getJson('/api/v1/stays')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.room.number', '201')
            ->assertJsonPath('data.0.guest.full_name', 'John Doe');
    }
}
