<?php

/*
|--------------------------------------------------------------------------
| Online payments
|--------------------------------------------------------------------------
| Gateway credentials are NOT here - administrators enter them in
| Staff > Settings > Payment gateways (encrypted in the database).
|
| Fee schedules are the gateways' published Nigerian local rates, used to
| work out the processing fee added for the payer. Administrators can
| change them per gateway in the same settings screen if a negotiated rate
| applies. Checked 29 Sep 2026:
|   Paystack    1.5% + ₦100 (₦100 waived under ₦2,500), capped at ₦2,000
|   Flutterwave 2.0% (1.4% transaction + 0.6% platform), no published cap
*/

return [
    'default_fees' => [
        'paystack' => [
            'percent' => '1.5',
            'flat' => '100.00',
            'flat_waived_below' => '2500.00',
            'cap' => '2000.00',
        ],
        'flutterwave' => [
            'percent' => '2.0',
            'flat' => '0.00',
            'flat_waived_below' => null,
            'cap' => null,
        ],
    ],

    // Starting a payment keeps an unpaid hold alive for at least this long,
    // so a guest is not cut off while on the gateway's page (max 3 times).
    'hold_extension_minutes' => 15,
    'max_hold_extensions' => 3,

    // Background reconciliation of payments whose redirect/webhook never arrived.
    'reconcile_after_minutes' => 5,
    'abandon_after_hours' => 48,

    'http_timeout' => 20,
];
