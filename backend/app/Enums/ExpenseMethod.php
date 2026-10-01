<?php

namespace App\Enums;

enum ExpenseMethod: string
{
    case Cash = 'CASH';
    case BankTransfer = 'BANK_TRANSFER';
    case Pos = 'POS';
    case Cheque = 'CHEQUE';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::Pos => 'POS / card',
            self::Cheque => 'Cheque',
        };
    }
}
