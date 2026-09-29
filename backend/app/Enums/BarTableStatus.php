<?php

namespace App\Enums;

enum BarTableStatus: string
{
    case Available = 'AVAILABLE';
    case Occupied = 'OCCUPIED';
    case Reserved = 'RESERVED';
    case Cleaning = 'CLEANING';
    case Blocked = 'BLOCKED';
}
