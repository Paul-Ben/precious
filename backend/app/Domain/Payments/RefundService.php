<?php

namespace App\Domain\Payments;

use App\Domain\Audit\AuditService;
use App\Domain\Property\HotelSettings;
use App\Domain\Reservations\ReservationLedger;
use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\DocumentSequence;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Refund workflow (ASSUMPTIONS P6, P14):
 *   request → (second approval when above the threshold) → complete.
 *
 * The money itself goes back outside the system for now (gateway dashboard,
 * cash or bank transfer); "complete" records that it was sent and reduces
 * what the reservation has paid. Gateway processing fees are not refunded.
 */
class RefundService
{
    public function __construct(
        private readonly HotelSettings $settings,
        private readonly ReservationLedger $ledger,
        private readonly AuditService $audit,
    ) {}

    public function request(Payment $payment, string $amount, string $reason, User $actor): Refund
    {
        return DB::transaction(function () use ($payment, $amount, $reason, $actor) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isSuccessful()) {
                throw new BusinessRuleException('Only successful payments can be refunded.', 'NOT_REFUNDABLE', 422);
            }

            $minor = Money::toMinor($amount);
            $available = $locked->refundableMinor();

            if ($minor <= 0 || $minor > $available) {
                throw new BusinessRuleException(
                    'The refund must be between ₦0.01 and '.Money::format(max(0, $available)).' (processing fees are not refundable).',
                    'INVALID_AMOUNT',
                    422,
                    ['refundable' => Money::toDecimal(max(0, $available))]
                );
            }

            $threshold = Money::toMinor((string) $this->settings->get('refund_second_approval_above'));
            $needsSecond = $minor > $threshold;

            $refund = Refund::create([
                'number' => DocumentSequence::next('RFD', (int) $this->settings->today()->year),
                'payment_id' => $locked->id,
                'amount' => Money::toDecimal($minor),
                'reason' => $reason,
                'requires_second_approval' => $needsSecond,
                // Below the threshold the requester's own permission is the approval.
                'status' => $needsSecond ? RefundStatus::Requested : RefundStatus::Approved,
                'requested_by' => $actor->id,
                'approved_by' => $needsSecond ? null : $actor->id,
                'approved_at' => $needsSecond ? null : now(),
            ]);

            $this->audit->record('refunds.requested', $refund, null, [
                'number' => $refund->number,
                'amount' => $refund->amount,
                'status' => $refund->status->value,
                'requires_second_approval' => $needsSecond,
            ], ['payment' => $locked->reference, 'reason' => $reason], $actor);

            return $refund;
        });
    }

    public function approve(Refund $refund, User $actor, ?string $note = null): Refund
    {
        return $this->transition($refund, function (Refund $locked) use ($actor, $note) {
            if ($locked->status !== RefundStatus::Requested) {
                throw new BusinessRuleException('Only requested refunds can be approved.', 'INVALID_STATUS', 422);
            }

            if ($locked->requested_by === $actor->id) {
                throw new BusinessRuleException('A different person must approve a refund you requested.', 'SELF_APPROVAL', 403);
            }

            $locked->forceFill([
                'status' => RefundStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->record('refunds.approved', $locked, ['status' => 'REQUESTED'], ['status' => 'APPROVED'], ['note' => $note], $actor);
        });
    }

    public function reject(Refund $refund, User $actor, string $note): Refund
    {
        return $this->transition($refund, function (Refund $locked) use ($actor, $note) {
            if (! in_array($locked->status, [RefundStatus::Requested, RefundStatus::Approved], true)) {
                throw new BusinessRuleException('This refund can no longer be rejected.', 'INVALID_STATUS', 422);
            }

            $old = $locked->status->value;
            $locked->forceFill([
                'status' => RefundStatus::Rejected,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->record('refunds.rejected', $locked, ['status' => $old], ['status' => 'REJECTED'], ['note' => $note], $actor);
        });
    }

    /** Records that the money was sent back. */
    public function complete(Refund $refund, User $actor, RefundMethod $method, ?string $externalReference): Refund
    {
        return $this->transition($refund, function (Refund $locked) use ($actor, $method, $externalReference) {
            if ($locked->status !== RefundStatus::Approved) {
                throw new BusinessRuleException('Only approved refunds can be completed.', 'INVALID_STATUS', 422);
            }

            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($locked->payment_id)->lockForUpdate()->firstOrFail();
            $refunded = Money::toMinor($payment->refunded_amount) + Money::toMinor($locked->amount);

            $payment->forceFill(['refunded_amount' => Money::toDecimal($refunded)]);

            if ($payment->needs_attention && $refunded >= Money::toMinor($payment->amount)) {
                $payment->forceFill(['needs_attention' => false]);
            }

            $payment->save();

            $locked->forceFill([
                'status' => RefundStatus::Completed,
                'method' => $method,
                'external_reference' => $externalReference,
                'completed_by' => $actor->id,
                'completed_at' => now(),
            ])->save();

            if ($payment->payable_type === (new Reservation)->getMorphClass()) {
                $reservation = Reservation::query()->whereKey($payment->payable_id)->lockForUpdate()->firstOrFail();
                $this->ledger->debit($reservation, $locked);
            }

            $this->audit->record('refunds.completed', $locked, ['status' => 'APPROVED'], ['status' => 'COMPLETED'], [
                'method' => $method->value,
                'external_reference' => $externalReference,
                'payment' => $payment->reference,
            ], $actor);
        });
    }

    private function transition(Refund $refund, callable $change): Refund
    {
        return DB::transaction(function () use ($refund, $change) {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $change($locked);

            return $locked->refresh()->load(['payment', 'requestedBy', 'approvedBy', 'rejectedBy', 'completedBy']);
        });
    }
}
