<?php

namespace Tests\Feature\Payments;

use App\Enums\ReservationStatus;
use App\Models\Payment;
use App\Models\Receipt;
use App\Notifications\PaymentReceiptNotification;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeskPaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    #[Test]
    public function reception_records_a_cash_payment_that_confirms_the_booking(): void
    {
        [$reservation] = $this->bookOnline($this->roomType('Deluxe', '50000.00'));
        $receptionist = $this->staff('Receptionist');

        $this->actingAsUser($receptionist)
            ->postJson("/api/v1/reservations/{$reservation->id}/payments", ['method' => 'CASH', 'amount' => '161250.00'])
            ->assertCreated()
            ->assertJsonPath('data.method', 'CASH')
            ->assertJsonPath('data.purpose', 'FULL')
            ->assertJsonPath('data.status', 'SUCCESSFUL')
            ->assertJsonPath('data.customer_fee', '0.00')
            ->assertJsonPath('data.recorded_by.id', $receptionist->id);

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame('PAID', $reservation->payment_status->value);

        $receipt = Receipt::firstOrFail();
        $this->assertSame('Cash', $receipt->snapshot['payment']['method_label']);
        $this->assertSame($receptionist->name, $receipt->snapshot['received_by']);
        Notification::assertSentOnDemand(PaymentReceiptNotification::class);

        $this->getJson("/api/v1/receipts/{$receipt->number}")->assertOk()
            ->assertJsonPath('data.number', $receipt->number)
            ->assertJsonPath('data.balance_after', '0.00');
    }

    #[Test]
    public function a_part_payment_below_the_deposit_keeps_the_booking_pending(): void
    {
        [$reservation] = $this->bookOnline($this->roomType('Deluxe', '50000.00'));

        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/reservations/{$reservation->id}/payments", ['method' => 'POS', 'amount' => '10000', 'external_reference' => 'POS-7781', 'send_receipt' => false])
            ->assertCreated()
            ->assertJsonPath('data.purpose', 'PART');

        $reservation->refresh();
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->status);
        $this->assertSame('PARTIALLY_PAID', $reservation->payment_status->value);
        Notification::assertNothingSent();
    }

    #[Test]
    public function desk_payments_are_validated(): void
    {
        [$reservation] = $this->bookOnline($this->roomType('Deluxe', '50000.00'));
        $this->actingAsUser($this->staff('Receptionist'));
        $url = "/api/v1/reservations/{$reservation->id}/payments";

        $this->postJson($url, ['method' => 'CASH', 'amount' => '161250.01'])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_AMOUNT')->assertJsonPath('balance', '161250.00');
        $this->postJson($url, ['method' => 'BANK_TRANSFER', 'amount' => '1000'])
            ->assertStatus(422)->assertJsonValidationErrors('external_reference');
        $this->postJson($url, ['method' => 'GATEWAY', 'amount' => '1000'])
            ->assertStatus(422)->assertJsonValidationErrors('method');
        $this->postJson($url, ['method' => 'CASH', 'amount' => '12.345'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function only_staff_with_payments_create_can_record_payments(): void
    {
        [$reservation] = $this->bookOnline($this->roomType());

        $this->actingAsUser($this->staff('Bartender'))
            ->postJson("/api/v1/reservations/{$reservation->id}/payments", ['method' => 'CASH', 'amount' => '1000'])
            ->assertForbidden();

        $this->actingAsUser($this->staff('Auditor'))
            ->postJson("/api/v1/reservations/{$reservation->id}/payments", ['method' => 'CASH', 'amount' => '1000'])
            ->assertForbidden();

        $this->actingAsUser($this->staff('Auditor'))->getJson('/api/v1/payments')->assertOk();
    }

    #[Test]
    public function cancelled_reservations_cannot_take_desk_payments(): void
    {
        [$reservation] = $this->bookOnline($this->roomType());
        $this->actingAsUser($this->staff('Hotel Manager'));
        $this->postJson("/api/v1/reservations/{$reservation->id}/cancel", ['reason' => 'Guest changed plans'])->assertOk();

        $this->postJson("/api/v1/reservations/{$reservation->id}/payments", ['method' => 'CASH', 'amount' => '1000'])
            ->assertStatus(409)->assertJsonPath('code', 'NOT_PAYABLE');
    }

    #[Test]
    public function the_daily_cash_up_totals_money_by_method(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201', '202']);
        [$first] = $this->bookOnline($type);
        [$second] = $this->bookOnline($type, overrides: ['guest' => ['email' => 'jane@example.com']]);

        $this->actingAsUser($this->staff('Accountant'));
        $this->postJson("/api/v1/reservations/{$first->id}/payments", ['method' => 'CASH', 'amount' => '50000'])->assertCreated();
        $this->postJson("/api/v1/reservations/{$second->id}/payments", ['method' => 'POS', 'amount' => '60000.50'])->assertCreated();

        $response = $this->getJson('/api/v1/payments/summary')->assertOk()
            ->assertJsonPath('data.received', '110000.50')
            ->assertJsonPath('data.refunded', '0.00')
            ->assertJsonPath('data.net', '110000.50')
            ->assertJsonPath('data.cash_in_hand', '50000.00');

        $pos = collect($response->json('data.by_method'))->firstWhere('method', 'POS');
        $this->assertSame(1, $pos['count']);
        $this->assertSame('60000.50', $pos['amount']);

        $this->getJson('/api/v1/payments?method=CASH')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/payments?search='.$second->number)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payable.number', $second->number);
        $this->getJson("/api/v1/reservations/{$first->id}/payments")->assertOk()->assertJsonCount(1, 'data');
    }
}
