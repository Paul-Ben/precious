<?php

namespace Tests\Feature\Stays;

use App\Models\AuditLog;
use App\Models\FolioStatement;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Notifications\FolioStatementNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckOutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Africa/Lagos'));
    }

    /** Checked in today for 2 nights (₦107,500 incl. VAT), then moved to departure day at $time. */
    private function departing(string $time = '11:00', bool $fullyPaid = true): Reservation
    {
        $reservation = $this->checkIn($this->confirmedBooking($this->roomType('Deluxe', '50000.00', ['201']), fullyPaid: $fullyPaid));
        $this->travelTo(CarbonImmutable::parse('2026-10-07 '.$time, 'Africa/Lagos'));
        $this->actingAsUser($this->staff('Receptionist'));

        return $reservation;
    }

    #[Test]
    public function a_settled_guest_checks_out_and_gets_a_final_bill(): void
    {
        $reservation = $this->departing('11:00');

        $this->getJson("/api/v1/reservations/{$reservation->id}/check-out")
            ->assertOk()
            ->assertJsonPath('data.can_check_out', true)
            ->assertJsonPath('data.late_fee.percent', 0)
            ->assertJsonPath('data.balance_with_late_fee', '0.00')
            ->assertJsonPath('data.can_override_balance', false);

        $number = $this->postJson("/api/v1/reservations/{$reservation->id}/check-out")
            ->assertOk()
            ->assertJsonPath('data.reservation.status', 'CHECKED_OUT')
            ->assertJsonPath('data.reservation.stays.0.status', 'CLOSED')
            ->json('data.statement_number');

        $this->assertMatchesRegularExpression('/^FOL-\d{4}-00001$/', $number);
        $this->assertSame('DIRTY', Room::where('number', '201')->first()->status->value);

        $statement = FolioStatement::where('number', $number)->firstOrFail();
        $this->assertSame('107500.00', $statement->snapshot['grand_total']);
        $this->assertSame('0.00', $statement->snapshot['balance']);
        Notification::assertSentOnDemand(FolioStatementNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'john.doe@example.com');

        $this->getJson("/api/v1/folios/{$number}")->assertOk()->assertJsonPath('data.reservation.number', $reservation->number);
    }

    #[Test]
    public function p18_check_out_is_blocked_while_money_is_owed(): void
    {
        $reservation = $this->departing('11:00', fullyPaid: false);

        $this->postJson("/api/v1/reservations/{$reservation->id}/check-out")
            ->assertStatus(409)
            ->assertJsonPath('code', 'BALANCE_DUE')
            ->assertJsonPath('balance', '75250.00');

        // A receptionist cannot override …
        $this->postJson("/api/v1/reservations/{$reservation->id}/check-out", ['override_balance' => true, 'override_reason' => 'Company pays'])
            ->assertForbidden();

        // … pays instead.
        $this->postJson("/api/v1/reservations/{$reservation->id}/payments", ['method' => 'POS', 'amount' => '75250', 'send_receipt' => false])->assertCreated();
        $this->postJson("/api/v1/reservations/{$reservation->id}/check-out")->assertOk();
    }

    #[Test]
    public function p18_a_manager_can_check_out_with_a_balance_and_a_reason(): void
    {
        $reservation = $this->departing('11:00', fullyPaid: false);
        $this->actingAsUser($this->staff('Hotel Manager'));

        $this->postJson("/api/v1/reservations/{$reservation->id}/check-out", ['override_balance' => true])
            ->assertStatus(422)->assertJsonValidationErrors('override_reason');

        $this->postJson("/api/v1/reservations/{$reservation->id}/check-out", ['override_balance' => true, 'override_reason' => 'Invoiced to Acme Ltd'])
            ->assertOk()
            ->assertJsonPath('data.reservation.status', 'CHECKED_OUT')
            ->assertJsonPath('data.reservation.balance_at_checkout', '75250.00');

        $this->assertTrue(AuditLog::where('action', 'reservations.checked_out_with_balance')->exists());
    }

    #[Test]
    public function p4_leaving_after_noon_adds_half_a_night(): void
    {
        $reservation = $this->departing('14:00');

        // 50% of ₦50,000 + 7.5% VAT.
        $this->getJson("/api/v1/reservations/{$reservation->id}/check-out")
            ->assertJsonPath('data.late_fee.percent', 50)
            ->assertJsonPath('data.late_fee.total', '26875.00')
            ->assertJsonPath('data.balance_with_late_fee', '26875.00');

        // The fee is put on the bill even though check-out then waits for payment.
        $this->postJson("/api/v1/reservations/{$reservation->id}/check-out")
            ->assertStatus(409)->assertJsonPath('balance', '26875.00');
        $this->getJson("/api/v1/reservations/{$reservation->id}/check-out")
            ->assertJsonPath('data.late_fee.already_charged', true)
            ->assertJsonPath('data.balance_with_late_fee', '26875.00');

        $this->postJson("/api/v1/reservations/{$reservation->id}/payments", ['method' => 'CASH', 'amount' => '26875', 'send_receipt' => false])->assertCreated();
        $this->postJson("/api/v1/reservations/{$reservation->id}/check-out")->assertOk();

        // Charged once.
        $this->assertSame('26875.00', $reservation->refresh()->charges_total);
    }

    #[Test]
    public function p4_after_six_pm_a_full_night_is_charged(): void
    {
        $reservation = $this->departing('19:30');

        $this->getJson("/api/v1/reservations/{$reservation->id}/check-out")
            ->assertJsonPath('data.late_fee.percent', 100)
            ->assertJsonPath('data.late_fee.total', '53750.00');
    }

    #[Test]
    public function a_late_fee_can_be_waived_with_a_reason(): void
    {
        $reservation = $this->departing('15:00');

        $this->postJson("/api/v1/reservations/{$reservation->id}/check-out", ['apply_late_fee' => false])
            ->assertStatus(422)->assertJsonValidationErrors('waive_reason');

        $this->postJson("/api/v1/reservations/{$reservation->id}/check-out", ['apply_late_fee' => false, 'waive_reason' => 'Flight delayed'])
            ->assertOk();

        $this->assertSame('0.00', $reservation->refresh()->charges_total);
        $this->assertTrue(AuditLog::where('action', 'reservations.late_fee_waived')->exists());
    }

    #[Test]
    public function p17_leaving_early_frees_the_room_but_keeps_the_booked_nights(): void
    {
        $reservation = $this->checkIn($this->confirmedBooking($this->roomType('Deluxe', '50000.00', ['201']), nights: 3));
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00', 'Africa/Lagos'));
        $this->actingAsUser($this->staff('Receptionist'));

        $this->getJson("/api/v1/reservations/{$reservation->id}/check-out")->assertJsonPath('data.early_departure', true);
        $this->postJson("/api/v1/reservations/{$reservation->id}/check-out")->assertOk();

        $this->assertSame('2026-10-06', ReservationRoom::first()->check_out->toDateString());
        $this->assertSame('161250.00', $reservation->refresh()->total);

        // Room 201 can be sold again from tonight.
        $this->withoutBearer();
        $this->getJson('/api/v1/public/availability?check_in=2026-10-06&check_out=2026-10-08&adults=1')
            ->assertOk()
            ->assertJsonPath('data.room_types.0.available_count', 1);
    }

    #[Test]
    public function an_overstaying_guest_must_be_extended_first(): void
    {
        $reservation = $this->departing('11:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Africa/Lagos'));
        $this->actingAsUser($this->staff('Receptionist')); // tokens expire after an hour

        $this->postJson("/api/v1/reservations/{$reservation->id}/check-out")
            ->assertStatus(409)->assertJsonPath('code', 'OVERSTAY');

        $this->postJson("/api/v1/reservations/{$reservation->id}/extend", ['check_out' => '2026-10-08'])->assertOk();
        $this->postJson("/api/v1/reservations/{$reservation->id}/payments", ['method' => 'CASH', 'amount' => '53750', 'send_receipt' => false])->assertCreated();
        $this->postJson("/api/v1/reservations/{$reservation->id}/check-out")->assertOk();
    }
}
