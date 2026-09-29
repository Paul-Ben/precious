<?php

namespace App\Enums;

/**
 * Reservation lifecycle (spec §11). Payment progress is tracked separately in
 * PaymentStatus, so DRAFT, PARTIALLY_PAID and CHECK_IN_PENDING exist for spec
 * compatibility but are not used by the current workflow.
 */
enum ReservationStatus: string
{
    case Draft = 'DRAFT';
    case PendingPayment = 'PENDING_PAYMENT';
    case Confirmed = 'CONFIRMED';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case CheckInPending = 'CHECK_IN_PENDING';
    case CheckedIn = 'CHECKED_IN';
    case CheckedOut = 'CHECKED_OUT';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
    case NoShow = 'NO_SHOW';

    /** Statuses in which the reservation holds its rooms. */
    public function holdsInventory(): bool
    {
        return in_array($this, [self::PendingPayment, self::Confirmed, self::PartiallyPaid, self::CheckInPending, self::CheckedIn], true);
    }

    public function isCancellable(): bool
    {
        return in_array($this, [self::PendingPayment, self::Confirmed, self::PartiallyPaid], true);
    }
}
