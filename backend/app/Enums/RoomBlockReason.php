<?php

namespace App\Enums;

enum RoomBlockReason: string
{
    case Maintenance = 'MAINTENANCE';
    case OutOfService = 'OUT_OF_SERVICE';
    case Blocked = 'BLOCKED';
}
