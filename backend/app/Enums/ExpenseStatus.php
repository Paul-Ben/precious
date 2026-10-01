<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Void = 'VOID';
}
