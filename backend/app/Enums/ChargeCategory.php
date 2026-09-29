<?php

namespace App\Enums;

enum ChargeCategory: string
{
    case Service = 'SERVICE';
    case ExtraNight = 'EXTRA_NIGHT';
    case LateCheckout = 'LATE_CHECKOUT';
    case Adjustment = 'ADJUSTMENT';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Service',
            self::ExtraNight => 'Extra night',
            self::LateCheckout => 'Late check-out',
            self::Adjustment => 'Adjustment',
            self::Other => 'Other',
        };
    }
}
