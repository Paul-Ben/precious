<?php

namespace Tests\Feature\Hotel;

use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicBookingTest extends TestCase
{
    #[Test]
    public function a_guest_can_book_online_and_the_rooms_are_held_pending_payment(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);

        $response = $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(10), $this->day(13), [
            'special_requests' => 'Late arrival',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.reservation.status', 'PENDING_PAYMENT')
            ->assertJsonPath('data.reservation.payment_status', 'UNPAID')
            ->assertJsonPath('data.reservation.nights', 3)
            ->assertJsonPath('data.reservation.subtotal', '150000.00')
            ->assertJsonPath('data.reservation.tax_total', '11250.00')
            ->assertJsonPath('data.reservation.total', '161250.00')
            ->assertJsonPath('data.reservation.deposit_percent', '30.00')
            ->assertJsonPath('data.reservation.deposit_amount', '48375.00')
            ->assertJsonPath('data.reservation.balance', '161250.00')
            ->assertJsonPath('data.reservation.rooms.0.room_number', null)
            ->assertJsonPath('data.reservation.guest.full_name', 'John Doe');

        $number = $response->json('data.reservation.number');
        $this->assertMatchesRegularExpression('/^RES-\d{4}-\d{5}$/', $number);

        $reservation = Reservation::where('number', $number)->firstOrFail();
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, $reservation->expires_at->timestamp, 5);
        $this->assertTrue(AuditLog::where('action', 'reservations.created')->where('auditable_id', $reservation->id)->exists());

        // The lookup token lets the guest see the booking without an account.
        $token = $response->json('data.lookup_token');
        $this->getJson("/api/v1/public/reservations/{$number}?token={$token}")->assertOk()->assertJsonPath('data.number', $number);
        $this->getJson("/api/v1/public/reservations/{$number}?token=".str_repeat('x', 40))->assertNotFound();
        $this->getJson("/api/v1/public/reservations/{$number}")->assertNotFound();
    }

    #[Test]
    public function spec_1_a_room_that_is_already_taken_cannot_be_reserved(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);

        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(5), $this->day(7)))->assertCreated();

        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(6), $this->day(9), [
            'guest' => ['email' => 'other@example.com'],
        ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'ROOM_UNAVAILABLE');

        $this->assertSame(1, Reservation::count());
    }

    #[Test]
    public function spec_2_the_database_itself_rejects_overlapping_active_bookings_of_a_room(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);
        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(5), $this->day(8)))->assertCreated();

        $line = ReservationRoom::firstOrFail();

        // Simulates a race that slipped past the application checks.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('reservation_rooms_no_overlap');

        ReservationRoom::create([
            ...$line->only(['reservation_id', 'room_id', 'room_type_id', 'nightly_rate', 'nights', 'subtotal']),
            'check_in' => $this->day(6),
            'check_out' => $this->day(7),
            'is_active' => true,
        ]);
    }

    #[Test]
    public function an_unpaid_hold_expires_after_thirty_minutes_and_frees_the_room(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);
        $first = $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(5), $this->day(7)))->json('data.reservation.number');

        $this->travel(31)->minutes();
        Artisan::call('reservations:expire-holds');

        $this->assertSame(ReservationStatus::Expired, Reservation::where('number', $first)->firstOrFail()->status);
        $this->assertFalse(ReservationRoom::first()->is_active);

        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(5), $this->day(7), [
            'guest' => ['email' => 'second@example.com'],
        ]))->assertCreated();
    }

    #[Test]
    public function a_reservation_can_contain_multiple_rooms_and_guests(): void
    {
        $deluxe = $this->roomType('Deluxe', '50000.00', ['201', '202']);
        $suite = $this->roomType('Suite', '120000.00', ['401'], ['max_adults' => 3, 'max_occupancy' => 4]);

        $response = $this->postJson('/api/v1/public/reservations', $this->bookingPayload($deluxe, $this->day(3), $this->day(5), [
            'adults' => 5,
            'children' => 1,
            'rooms' => [
                ['room_type_id' => $deluxe->id, 'quantity' => 2],
                ['room_type_id' => $suite->id, 'quantity' => 1],
            ],
            'additional_guests' => [
                ['first_name' => 'Jane', 'last_name' => 'Doe'],
                ['first_name' => 'Tobi', 'last_name' => 'Doe'],
            ],
        ]))->assertCreated();

        // 2 nights × (2 × 50,000 + 120,000) = 440,000
        $response->assertJsonPath('data.reservation.subtotal', '440000.00')
            ->assertJsonCount(3, 'data.reservation.rooms')
            ->assertJsonCount(3, 'data.reservation.guests');

        $this->assertSame(3, ReservationRoom::where('is_active', true)->count());
        $this->assertSame(3, ReservationRoom::pluck('room_id')->unique()->count());
        $this->assertSame(6, (int) ReservationRoom::sum('adults') + (int) ReservationRoom::sum('children'));
    }

    #[Test]
    public function occupancy_dates_and_room_limits_are_validated(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201', '202']);

        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(3), $this->day(5), ['adults' => 4]))
            ->assertStatus(422)->assertJsonPath('code', 'OCCUPANCY_EXCEEDED');

        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(-1), $this->day(2)))
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_DATES');

        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(1), $this->day(40)))
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_DATES');

        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(1), $this->day(3), ['accept_terms' => false]))
            ->assertStatus(422)->assertJsonValidationErrors('accept_terms');
    }

    #[Test]
    public function prices_are_snapshotted_so_later_rate_changes_do_not_alter_bookings(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);
        $number = $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(3), $this->day(5)))->json('data.reservation.number');

        $type->update(['base_rate' => '90000.00']);

        $reservation = Reservation::where('number', $number)->firstOrFail();
        $this->assertSame('100000.00', $reservation->subtotal);
        $this->assertSame('50000.00', $reservation->rooms()->first()->nightly_rate);
        $this->assertSame('50000.00', $reservation->pricing_snapshot['lines'][0]['nightly_rate']);
    }

    #[Test]
    public function the_quote_endpoint_prices_a_basket_and_reports_shortages(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);

        $this->postJson('/api/v1/public/quote', [
            'check_in' => $this->day(1), 'check_out' => $this->day(2), 'adults' => 2,
            'rooms' => [['room_type_id' => $type->id, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('data.total', '53750.00')->assertJsonPath('data.available', true);

        $this->postJson('/api/v1/public/quote', [
            'check_in' => $this->day(1), 'check_out' => $this->day(2), 'adults' => 2,
            'rooms' => [['room_type_id' => $type->id, 'quantity' => 2]],
        ])->assertOk()->assertJsonPath('data.available', false);
    }

    #[Test]
    public function a_signed_in_customer_booking_is_linked_to_their_account_and_private(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201', '202']);
        $customer = $this->customer();

        $id = $this->actingAsUser($customer)
            ->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(3), $this->day(5)))
            ->assertCreated()
            ->json('data.reservation.id');

        $this->assertSame($customer->id, Guest::whereHas('reservations', fn ($q) => $q->whereKey($id))->value('user_id'));

        $this->getJson('/api/v1/me/reservations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/v1/me/reservations/{$id}")->assertOk();

        // Another customer cannot see or cancel it.
        $this->actingAsUser($this->customer())->getJson("/api/v1/me/reservations/{$id}")->assertNotFound();
        $this->postJson("/api/v1/me/reservations/{$id}/cancel", ['reason' => 'nope'])->assertNotFound();
    }

    #[Test]
    public function a_customer_can_cancel_their_booking_which_releases_the_room(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);
        $customer = $this->customer();

        $id = $this->actingAsUser($customer)
            ->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(3), $this->day(5)))
            ->json('data.reservation.id');

        $this->postJson("/api/v1/me/reservations/{$id}/cancel", ['reason' => 'Plans changed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.cancellation.reason', 'Plans changed');

        $this->assertFalse(ReservationRoom::first()->is_active);
        $this->assertSame(1, Room::count());

        $this->postJson("/api/v1/me/reservations/{$id}/cancel", ['reason' => 'again'])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_STATUS');
    }
}
