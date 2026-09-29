<?php

namespace App\Enums;

enum PaymentPurpose: string
{
    case Deposit = 'DEPOSIT';
    case Balance = 'BALANCE';
    case Full = 'FULL';
    case Part = 'PART';
}
