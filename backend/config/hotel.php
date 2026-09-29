<?php

/*
|--------------------------------------------------------------------------
| Hotel operating defaults
|--------------------------------------------------------------------------
| These are the confirmed business rules (ASSUMPTIONS.md, P1–P14). Every
| value can be overridden per property by an administrator
| (Staff > Settings > Property & policies); the override lives in the
| `settings` table. Values that are money or percentages are strings so
| they never pass through floating point.
*/

return [
    'defaults' => [
        // P8 / spec §14 – share of the stay total required to confirm.
        'deposit_percent' => '30',
        // P5 – how long an unpaid online reservation holds its rooms.
        'hold_minutes' => 30,
        // Front-desk bookings may be held longer (e.g. awaiting a transfer).
        'staff_hold_max_minutes' => 4320,
        // P1 – VAT.
        'vat_percent' => '7.5',
        'vat_on_accommodation' => true,
        // P2 – service charge (bar and room service); none on accommodation.
        'service_charge_percent' => '10',
        'accommodation_service_charge_percent' => '0',
        // P3 / P4.
        'check_in_time' => '14:00',
        'check_out_time' => '12:00',
        'late_checkout_half_rate_until' => '18:00',
        // P6 / P7.
        'free_cancellation_hours' => 48,
        'no_show_time' => '23:59',
        // Booking limits.
        'max_nights' => 30,
        'max_rooms_per_booking' => 5,
        'booking_window_days' => 365,
        // Owner decision (29 Sep 2026): the payer covers online gateway fees.
        'pass_gateway_fees_to_customer' => true,
        // P15 – an ID must be on file (or recorded at the desk) before check-in.
        'require_id_at_check_in' => true,
        // P14 – refunds above this amount need a second approver.
        'refund_second_approval_above' => '100000.00',
    ],

    // Where room photos (public) and guest ID documents (private) are stored.
    // Production: MEDIA_DISK=r2_public, DOCUMENTS_DISK=r2_private.
    'media_disk' => env('MEDIA_DISK', 'public'),
    'documents_disk' => env('DOCUMENTS_DISK', 'local'),

    'currency' => 'NGN',
];
