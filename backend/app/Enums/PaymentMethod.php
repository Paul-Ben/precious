<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Gateway = 'GATEWAY';
    case Cash = 'CASH';
    case Pos = 'POS';
    case BankTransfer = 'BANK_TRANSFER';

    public function label(): string
    {
        return match ($this) {
            self::Gateway => 'Online payment',
            self::Cash => 'Cash',
            self::Pos => 'POS terminal',
            self::BankTransfer => 'Bank transfer',
        };
    }

    /** Methods staff can record by hand at the desk. */
    public static function manual(): array
    {
        return [self::Cash, self::Pos, self::BankTransfer];
    }
}
