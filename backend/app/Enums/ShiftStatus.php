<?php

namespace App\Enums;

enum ShiftStatus: string
{
    case Scheduled = 'SCHEDULED';
    case Cancelled = 'CANCELLED';
}
