<?php

namespace App\Enums;

enum EmploymentStatus: string
{
    case Active = 'ACTIVE';
    case OnLeave = 'ON_LEAVE';
    case Left = 'LEFT';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::OnLeave => 'On leave',
            self::Left => 'Left',
        };
    }
}
