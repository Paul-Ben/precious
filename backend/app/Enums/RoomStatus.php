<?php

namespace App\Enums;

enum RoomStatus: string
{
    case Available = 'AVAILABLE';
    case Reserved = 'RESERVED';
    case Occupied = 'OCCUPIED';
    case Dirty = 'DIRTY';
    case Cleaning = 'CLEANING';
    case Maintenance = 'MAINTENANCE';
    case OutOfService = 'OUT_OF_SERVICE';
    case Blocked = 'BLOCKED';

    /** Statuses that make a room unsellable for any date until changed. */
    public function isSellable(): bool
    {
        return $this !== self::OutOfService;
    }
}
