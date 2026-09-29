<?php

namespace App\Enums;

enum BarTabStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';
}
