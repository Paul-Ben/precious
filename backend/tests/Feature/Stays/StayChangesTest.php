<?php

namespace Tests\Feature\Stays;

use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Service;
use App\Models\Stay;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StayChangesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Africa/Lagos'));
    }

    #[Test]
    public function extending_a_stay_bills_the_extra_nights_at_the_booked_rate(): void
    {
        // 2 nights × ₦50,000 + 7.5% VAT = ₦107,500, fully paid.
        $reservation = $this->checkIn($this->confirmedBooking($this->roomType('Deluxe', '50000.00', ['201'])));

        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/reservations/{$reservation->id}/extend", ['check_out' => $this->day(3)])
            ->assertOk()
            ->assertJsonPath('data.check_out', $this->day(3))
            ->assertJsonPath('data.nights', 3)
            ->assertJsonPath('data.charges.0.category', 'EXTRA_NIGHT')
            ->assertJsonPath('data.charges.0.subtotal', '50000.00')
            ->assertJsonPath('data.charges.0.vat', '3750.00')
            ->assertJsonPath('data.charges.0.total', '53750.00')
            ->assertJsonPath('data.charges_total', '53750.00')
            ->assertJsonPath('data.grand_total', '161250.00')
            ->assertJsonPath('data.balance', '53750.00')
            ->assertJsonPath('data.payment_status', 'DEPOSIT_PAID');

        $this->assertSame($this->day(3), ReservationRoom::first()->check_out->toDateString());
    }

    #[Test]
    public function a_stay_cannot_be_extended_into_another_guests_booking(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);
        $reservation = $this->checkIn($this->confirmedBooking($type));
        // Someone else has room 201 from the departure day.
        $this->withoutBearer();
        $this->bookOnline($type, 2, 2, ['guest' => ['email' => 'next@example.com']]);

        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/reservations/{$reservation->id}/extend", ['check_out' => $this->day(3)])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ROOM_UNAVAILABLE')
            ->assertJsonPath('room', '201');

        $this->assertSame('0.00', $reservation->refresh()->charges_total);
    }

    #[Test]
    public function a_guest_can_be_moved_mid_stay(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201', '202']);
        $reservation = $this->checkIn($this->confirmedBooking($type, nights: 3));
        $stay = Stay::firstOrFail();
        $from = $stay->room;
        $to = Room::where('number', $from->number === '201' ? '202' : '201')->firstOrFail();

        $this->travel(1)->days();

        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/stays/{$stay->id}/move", ['room_id' => $to->id, 'reason' => 'Air conditioning fault'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CHECKED_IN');

        $this->assertSame('CLOSED', $stay->refresh()->status->value);
        $this->assertSame('MOVED', $stay->close_reason);
        $this->assertSame('DIRTY', $from->refresh()->status->value);
        $this->assertSame('OCCUPIED', $to->refresh()->status->value);

        // Night 1 stays in the old room, nights 2-3 are in the new one.
        $lines = ReservationRoom::orderBy('check_in')->get();
        $this->assertCount(2, $lines);
        $this->assertSame([$from->id, 1], [$lines[0]->room_id, $lines[0]->nights]);
        $this->assertSame([$to->id, 2], [$lines[1]->room_id, $lines[1]->nights]);
        $this->assertSame(1, Stay::open()->count());
    }

    #[Test]
    public function services_are_added_with_service_charge_and_vat(): void
    {
        $reservation = $this->checkIn($this->confirmedBooking($this->roomType('Deluxe', '50000.00', ['201'])));
        $manager = $this->staff('Hotel Manager');

        $serviceId = $this->actingAsUser($manager)
            ->postJson('/api/v1/services', ['name' => 'Laundry', 'category' => 'Laundry', 'price' => '2000'])
            ->assertCreated()->json('data.id');

        // ₦2,000 × 2 = ₦4,000 + 10% service charge ₦400 + 7.5% VAT on ₦4,400 = ₦330.
        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/reservations/{$reservation->id}/charges", ['service_id' => $serviceId, 'quantity' => '2'])
            ->assertCreated()
            ->assertJsonPath('data.description', 'Laundry')
            ->assertJsonPath('data.subtotal', '4000.00')
            ->assertJsonPath('data.service_charge', '400.00')
            ->assertJsonPath('data.vat', '330.00')
            ->assertJsonPath('data.total', '4730.00')
            ->assertJsonPath('data.stay_id', Stay::first()->id);

        // Fully paid before; the new charge leaves a balance.
        $reservation->refresh();
        $this->assertSame('4730.00', $reservation->charges_total);
        $this->assertSame('DEPOSIT_PAID', $reservation->payment_status->value);
        $this->assertSame(4_730_00, $reservation->balanceMinor());
    }

    #[Test]
    public function other_charges_and_voids_update_the_bill(): void
    {
        $reservation = $this->checkIn($this->confirmedBooking($this->roomType()));
        $this->actingAsUser($this->staff('Receptionist'));

        $id = $this->postJson("/api/v1/reservations/{$reservation->id}/charges", [
            'category' => 'OTHER', 'description' => 'Broken glass', 'unit_price' => '5000', 'charges_vat' => false, 'charges_service_charge' => false,
        ])->assertCreated()->assertJsonPath('data.total', '5000.00')->json('data.id');

        $this->postJson("/api/v1/charges/{$id}/void")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/charges/{$id}/void", ['reason' => 'Posted in error'])->assertOk()->assertJsonPath('data.status', 'VOIDED');
        $this->postJson("/api/v1/charges/{$id}/void", ['reason' => 'Again'])->assertStatus(422);

        $this->assertSame('0.00', $reservation->refresh()->charges_total);
        $this->assertSame('PAID', $reservation->payment_status->value);
    }

    #[Test]
    public function reducing_a_bill_needs_discount_approval(): void
    {
        $reservation = $this->checkIn($this->confirmedBooking($this->roomType('Deluxe', '50000.00', ['201']), fullyPaid: false));
        $payload = ['category' => 'ADJUSTMENT', 'description' => 'Noise complaint', 'amount' => '10000', 'reason' => 'Manager goodwill'];

        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/reservations/{$reservation->id}/charges", $payload)
            ->assertForbidden();

        $this->actingAsUser($this->staff('Hotel Manager'))
            ->postJson("/api/v1/reservations/{$reservation->id}/charges", $payload)
            ->assertCreated()
            ->assertJsonPath('data.total', '-10000.00');

        // ₦107,500 − ₦10,000 = ₦97,500 due; ₦32,250 deposit paid.
        $this->assertSame('-10000.00', $reservation->refresh()->charges_total);
        $this->assertSame(97_500_00 - 32_250_00, $reservation->balanceMinor());
    }

    #[Test]
    public function charges_need_an_in_house_guest(): void
    {
        $reservation = $this->confirmedBooking($this->roomType());

        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/reservations/{$reservation->id}/charges", ['category' => 'OTHER', 'description' => 'Minibar', 'unit_price' => '1000'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'NOT_IN_HOUSE');
    }

    #[Test]
    public function only_managers_edit_the_services_price_list(): void
    {
        $this->actingAsUser($this->staff('Receptionist'));
        $this->postJson('/api/v1/services', ['name' => 'Spa', 'category' => 'Spa', 'price' => '15000'])->assertForbidden();
        $this->getJson('/api/v1/services')->assertOk();

        $this->actingAsUser($this->staff('Hotel Manager'));
        $id = $this->postJson('/api/v1/services', ['name' => 'Spa', 'category' => 'Spa', 'price' => '15000'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/services', ['name' => 'Spa', 'category' => 'Spa', 'price' => '1'])->assertStatus(422)->assertJsonPath('code', 'DUPLICATE_SERVICE');
        $this->patchJson("/api/v1/services/{$id}", ['price' => '17500.5'])->assertOk()->assertJsonPath('data.price', '17500.50');
        $this->deleteJson("/api/v1/services/{$id}")->assertOk();

        $this->assertSoftDeleted(Service::withTrashed()->find($id));
    }
}
