<?php

namespace Tests\Feature\Hotel;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StaffReservationTest extends TestCase
{
    #[Test]
    public function a_receptionist_books_for_an_existing_guest_with_a_longer_hold(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);
        $guest = Guest::factory()->create(['first_name' => 'Amaka', 'last_name' => 'Obi']);

        $response = $this->actingAsUser($this->staff('Receptionist'))->postJson('/api/v1/reservations', [
            'source' => 'PHONE',
            'guest_id' => $guest->id,
            'check_in' => $this->day(2),
            'check_out' => $this->day(4),
            'adults' => 2,
            'rooms' => [['room_type_id' => $type->id, 'quantity' => 1]],
            'hold_minutes' => 1440,
            'internal_notes' => 'Paying by transfer',
        ])->assertCreated()
            ->assertJsonPath('data.source', 'PHONE')
            ->assertJsonPath('data.guest.full_name', 'Amaka Obi')
            ->assertJsonPath('data.rooms.0.room_number', '201')
            ->assertJsonPath('data.internal_notes', 'Paying by transfer');

        $reservation = Reservation::findOrFail($response->json('data.id'));
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $reservation->expires_at->timestamp, 5);
    }

    #[Test]
    public function a_walk_in_can_be_booked_with_new_guest_details(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);

        $this->actingAsUser($this->staff('Receptionist'))->postJson('/api/v1/reservations', [
            'source' => 'WALK_IN',
            'guest' => ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348031112222'],
            'check_in' => $this->day(0),
            'check_out' => $this->day(1),
            'adults' => 1,
            'rooms' => [['room_type_id' => $type->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.guest.full_name', 'Tunde Bakare');
    }

    #[Test]
    public function staff_without_reservation_permissions_are_forbidden(): void
    {
        $this->actingAsUser($this->staff('Waiter'))->getJson('/api/v1/reservations')->assertForbidden();
        $this->postJson('/api/v1/reservations', [])->assertForbidden();
        $this->actingAsUser($this->customer())->getJson('/api/v1/reservations')->assertForbidden();
    }

    #[Test]
    public function reservations_can_be_searched_and_filtered(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201', '202']);
        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(1), $this->day(3)))->assertCreated();
        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(5), $this->day(6), [
            'guest' => ['first_name' => 'Fatima', 'last_name' => 'Bello', 'email' => 'fatima@example.com', 'phone' => '+2348099998888'],
        ]))->assertCreated();

        $this->actingAsUser($this->staff('Receptionist'));

        $this->getJson('/api/v1/reservations?search=fatima')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.guest.full_name', 'Fatima Bello');
        $this->getJson('/api/v1/reservations?search=99998888')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/reservations?arrivals_on='.$this->day(1))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/reservations?status=PENDING_PAYMENT,CONFIRMED')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/reservations?status=CANCELLED')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_booking_desk_availability_lists_free_room_numbers(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201', '202']);
        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(1), $this->day(3)))->assertCreated();

        $this->actingAsUser($this->staff('Receptionist'))
            ->getJson('/api/v1/reservations/availability?check_in='.$this->day(2).'&check_out='.$this->day(4))
            ->assertOk()
            ->assertJsonPath('data.room_types.0.available_count', 1)
            ->assertJsonPath('data.room_types.0.available_rooms.0.number', '202');
    }

    #[Test]
    public function cancelling_needs_the_cancel_permission_and_releases_rooms(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);
        $id = $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(1), $this->day(3)))->json('data.reservation.id');

        $auditor = $this->staff('Auditor');
        $this->actingAsUser($auditor)->postJson("/api/v1/reservations/{$id}/cancel", ['reason' => 'x'])->assertForbidden();

        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/reservations/{$id}/cancel", ['reason' => 'Guest called to cancel'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.cancellation.refund_eligible', null);

        $this->assertFalse(ReservationRoom::first()->is_active);
    }

    #[Test]
    public function confirmed_arrivals_that_never_check_in_become_no_shows(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);
        $id = $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(0), $this->day(2)))->json('data.reservation.id');
        Reservation::whereKey($id)->update(['status' => 'CONFIRMED']);

        Artisan::call('reservations:mark-no-shows', ['--date' => $this->day(0)]);

        $this->assertSame('NO_SHOW', Reservation::find($id)->status->value);
        $this->assertFalse(ReservationRoom::first()->is_active);
    }

    #[Test]
    public function the_front_desk_summary_counts_todays_movements(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201', '202', '203']);
        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(0), $this->day(2)))->assertCreated();

        $this->actingAsUser($this->staff('Receptionist'))
            ->getJson('/api/v1/front-desk/summary')
            ->assertOk()
            ->assertJsonPath('data.arrivals', 1)
            ->assertJsonPath('data.pending_payment', 1)
            ->assertJsonPath('data.available_tonight', 2)
            ->assertJsonPath('data.total_rooms', 3);
    }
}
