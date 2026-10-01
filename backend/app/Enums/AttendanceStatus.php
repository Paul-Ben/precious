<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case ClockedIn = 'CLOCKED_IN';
    case ClockedOut = 'CLOCKED_OUT';
    case Absent = 'ABSENT';
}
