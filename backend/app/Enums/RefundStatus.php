<?php

namespace App\Enums;

enum RefundStatus: string
{
    case Requested = 'REQUESTED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Completed = 'COMPLETED';

    /** Counts against the refundable amount of the payment. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::Completed], true);
    }
}
