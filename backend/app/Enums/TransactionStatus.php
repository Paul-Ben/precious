<?php

namespace App\Enums;

/** Status of a single payment transaction (not of the reservation's balance). */
enum TransactionStatus: string
{
    case Pending = 'PENDING';
    case Successful = 'SUCCESSFUL';
    case Failed = 'FAILED';
    case Abandoned = 'ABANDONED';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
