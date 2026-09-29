<?php

namespace App\Enums;

enum ReservationSource: string
{
    case Website = 'WEBSITE';
    case FrontDesk = 'FRONT_DESK';
    case Phone = 'PHONE';
    case WalkIn = 'WALK_IN';
}
