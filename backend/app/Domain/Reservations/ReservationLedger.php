<?php

namespace App\Domain\Reservations;

use App\Domain\Audit\AuditService;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Applies money to a reservation: payments increase amount_paid (and confirm
 * the booking once the deposit is covered), completed refunds decrease it.
 * Callers must already hold a row lock on the reservation.
 */
class ReservationLedger
{
    private const EXCLUSION_VIOLATION = '23P01';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return string|null attention reason when the money could not be applied normally
     */
    public function credit(Reservation $reservation, Payment $payment): ?string
    {
        $attention = null;

        if ($reservation->status === ReservationStatus::Expired) {
            // Paid after the hold lapsed: keep the booking if its rooms are still free.
            $attention = $this->reinstate($reservation) ? null : 'ROOM_NO_LONGER_AVAILABLE';
        } elseif (in_array($reservation->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true)) {
            $attention = 'RESERVATION_'.$reservation->status->value;
        }

        $paid = Money::toMinor($reservation->amount_paid) + Money::toMinor($payment->amount);
        $oldStatus = $reservation->status;

        $reservation->forceFill([
            'amount_paid' => Money::toDecimal($paid),
            'payment_status' => $this->paymentStatus($reservation, $paid),
        ]);

        if ($reservation->status === ReservationStatus::PendingPayment && $paid >= Money::toMinor($reservation->deposit_amount)) {
            $reservation->forceFill([
                'status' => ReservationStatus::Confirmed,
                'confirmed_at' => now(),
                'expires_at' => null,
            ]);
        }

        $reservation->save();

        if ($reservation->status !== $oldStatus) {
            $this->audit->record('reservations.confirmed', $reservation,
                ['status' => $oldStatus->value],
                ['status' => $reservation->status->value],
                ['payment_reference' => $payment->reference, 'amount_paid' => $reservation->amount_paid]
            );
        }

        if ($attention === null && $paid > $reservation->grandTotalMinor()) {
            $attention = 'OVERPAYMENT';
        }

        return $attention;
    }

    public function debit(Reservation $reservation, Refund $refund): void
    {
        $paid = max(0, Money::toMinor($reservation->amount_paid) - Money::toMinor($refund->amount));

        $reservation->forceFill([
            'amount_paid' => Money::toDecimal($paid),
            'payment_status' => $paid === 0 ? PaymentStatus::Refunded : $this->paymentStatus($reservation, $paid),
        ])->save();
    }

    /** Re-derives payment status after the bill total changed (charges added or voided). */
    public function recalculate(Reservation $reservation): void
    {
        $paid = Money::toMinor($reservation->amount_paid);

        if ($reservation->payment_status !== PaymentStatus::Refunded || $paid > 0) {
            $reservation->forceFill(['payment_status' => $this->paymentStatus($reservation, $paid)])->save();
        }
    }

    public function paymentStatus(Reservation $reservation, int $paidMinor): PaymentStatus
    {
        return match (true) {
            $paidMinor <= 0 => PaymentStatus::Unpaid,
            $paidMinor >= $reservation->grandTotalMinor() => PaymentStatus::Paid,
            $paidMinor >= Money::toMinor($reservation->deposit_amount) => PaymentStatus::DepositPaid,
            default => PaymentStatus::PartiallyPaid,
        };
    }

    /**
     * Re-activates an expired reservation's rooms (or other free rooms of the
     * same type). All or nothing; the exclusion constraint still guards it.
     */
    private function reinstate(Reservation $reservation): bool
    {
        $dates = StayDates::fromStrings($reservation->check_in->toDateString(), $reservation->check_out->toDateString());
        $availability = app(AvailabilityService::class);

        try {
            DB::transaction(function () use ($reservation, $dates, $availability) {
                $taken = [];

                foreach ($reservation->rooms()->get() as $line) {
                    /** @var ReservationRoom $line */
                    $room = $availability->availableRooms($dates, $line->room_type_id)
                        ->whereNotIn('rooms.id', $taken)
                        // Prefer the originally held room; availableRooms() already orders
                        // by room number, so reset the order to put this criterion first.
                        ->reorder()
                        ->orderByRaw('CASE WHEN rooms.id = ? THEN 0 ELSE 1 END', [$line->room_id])
                        ->orderByRaw('LENGTH(rooms.number), rooms.number')
                        ->lockForUpdate()
                        ->first();

                    if (! $room) {
                        throw new RoomsUnavailable;
                    }

                    $taken[] = $room->id;
                    $line->forceFill(['room_id' => $room->id, 'is_active' => true])->save();
                }
            });
        } catch (RoomsUnavailable) {
            return false;
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== self::EXCLUSION_VIOLATION) {
                throw $e;
            }

            return false;
        }

        $reservation->forceFill([
            'status' => ReservationStatus::PendingPayment,
            'expires_at' => now()->addMinutes((int) config('payments.hold_extension_minutes', 15)),
        ])->save();

        $this->audit->record('reservations.reinstated', $reservation, ['status' => 'EXPIRED'], ['status' => 'PENDING_PAYMENT'], [
            'reason' => 'Payment received after the hold expired; rooms were still free.',
        ]);

        return true;
    }
}
