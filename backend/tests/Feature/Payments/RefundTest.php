<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\Refund;
use App\Models\Reservation;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RefundTest extends TestCase
{
    private Reservation $reservation;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        // 3 nights × ₦100,000 + VAT = ₦322,500, paid in cash.
        [$this->reservation] = $this->bookOnline($this->roomType('Suite', '100000.00'));

        $id = $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/reservations/{$this->reservation->id}/payments", ['method' => 'CASH', 'amount' => '322500'])
            ->assertCreated()->json('data.id');

        $this->payment = Payment::findOrFail($id);
    }

    #[Test]
    public function a_small_refund_is_approved_by_the_requester_and_then_completed(): void
    {
        $accountant = $this->staff('Accountant');
        $this->actingAsUser($accountant);

        $refundId = $this->postJson("/api/v1/payments/{$this->payment->id}/refunds", ['amount' => '50000', 'reason' => 'Room downgrade'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.requires_second_approval', false)
            ->assertJsonPath('data.approved_by.id', $accountant->id)
            ->json('data.id');

        $this->postJson("/api/v1/refunds/{$refundId}/complete", ['method' => 'CASH'])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertSame('50000.00', $this->payment->refresh()->refunded_amount);
        $this->reservation->refresh();
        $this->assertSame('272500.00', $this->reservation->amount_paid);
        $this->assertSame('DEPOSIT_PAID', $this->reservation->payment_status->value);

        $this->getJson('/api/v1/payments/summary')->assertJsonPath('data.refunded', '50000.00')
            ->assertJsonPath('data.cash_in_hand', '272500.00');
    }

    #[Test]
    public function p14_a_refund_above_100k_needs_a_different_approver(): void
    {
        $requester = $this->staff('Accountant');
        $refundId = $this->actingAsUser($requester)
            ->postJson("/api/v1/payments/{$this->payment->id}/refunds", ['amount' => '150000', 'reason' => 'Early departure'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'REQUESTED')
            ->assertJsonPath('data.requires_second_approval', true)
            ->json('data.id');

        // Cannot complete before approval, cannot approve own request.
        $this->postJson("/api/v1/refunds/{$refundId}/complete", ['method' => 'BANK_TRANSFER', 'external_reference' => 'TRF-1'])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_STATUS');
        $this->postJson("/api/v1/refunds/{$refundId}/approve")
            ->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');

        // Staff without payments.refund cannot approve either.
        $this->actingAsUser($this->staff('Hotel Manager'))->postJson("/api/v1/refunds/{$refundId}/approve")->assertForbidden();

        $approver = $this->staff('Accountant');
        $this->actingAsUser($approver)->postJson("/api/v1/refunds/{$refundId}/approve", ['note' => 'Checked with manager'])
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.approved_by.id', $approver->id);

        $this->postJson("/api/v1/refunds/{$refundId}/complete", ['method' => 'BANK_TRANSFER'])
            ->assertStatus(422)->assertJsonValidationErrors('external_reference');
        $this->postJson("/api/v1/refunds/{$refundId}/complete", ['method' => 'BANK_TRANSFER', 'external_reference' => 'TRF-1'])->assertOk();

        $this->assertSame('172500.00', $this->reservation->refresh()->amount_paid);
    }

    #[Test]
    public function refunds_cannot_exceed_what_was_paid(): void
    {
        $this->actingAsUser($this->staff('Accountant'));

        $this->postJson("/api/v1/payments/{$this->payment->id}/refunds", ['amount' => '300000', 'reason' => 'First part'])->assertCreated();

        $this->postJson("/api/v1/payments/{$this->payment->id}/refunds", ['amount' => '22500.01', 'reason' => 'Too much'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_AMOUNT')
            ->assertJsonPath('refundable', '22500.00');
    }

    #[Test]
    public function a_rejected_refund_frees_the_amount_again(): void
    {
        $this->actingAsUser($this->staff('Accountant'));
        $refundId = $this->postJson("/api/v1/payments/{$this->payment->id}/refunds", ['amount' => '322500', 'reason' => 'Full refund'])->json('data.id');

        $this->actingAsUser($this->staff('Accountant'));
        $this->postJson("/api/v1/refunds/{$refundId}/reject")->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/refunds/{$refundId}/reject", ['note' => 'Not eligible - inside 48 hours'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED');

        $this->getJson("/api/v1/payments/{$this->payment->id}")->assertJsonPath('data.refundable', '322500.00');
        $this->getJson('/api/v1/refunds?status=REJECTED')->assertJsonCount(1, 'data');
        $this->assertSame('322500.00', $this->reservation->refresh()->amount_paid);
        $this->assertSame(1, Refund::count());
    }

    #[Test]
    public function receptionists_cannot_refund(): void
    {
        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/payments/{$this->payment->id}/refunds", ['amount' => '1000', 'reason' => 'Goodwill'])
            ->assertForbidden();
    }
}
