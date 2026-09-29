<?php

namespace App\Enums;

enum BarOrderStatus: string
{
    case Placed = 'PLACED';
    case Accepted = 'ACCEPTED';
    case Preparing = 'PREPARING';
    case Ready = 'READY';
    case Delivered = 'DELIVERED';
    case Cancelled = 'CANCELLED';

    /** Still with the bar (shown in the bartender's queue). */
    public function isInProgress(): bool
    {
        return in_array($this, [self::Placed, self::Accepted, self::Preparing, self::Ready], true);
    }

    /** Next status in the bartender's flow, or null. */
    public function next(): ?self
    {
        return match ($this) {
            self::Placed => self::Accepted,
            self::Accepted => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => self::Delivered,
            default => null,
        };
    }
}
