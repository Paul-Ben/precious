<?php

namespace App\Enums;

enum RefundMethod: string
{
    // Refund issued from the Paystack/Flutterwave dashboard back to the card/account.
    case GatewayDashboard = 'GATEWAY_DASHBOARD';
    case Cash = 'CASH';
    case BankTransfer = 'BANK_TRANSFER';
}
