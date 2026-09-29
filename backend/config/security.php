<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    | Used for links in emails (password reset) and CORS.
    */
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Super administrator role
    |--------------------------------------------------------------------------
    | Users with this role pass every permission check (Gate::before).
    */
    'super_admin_role' => 'Super Administrator',

    /*
    |--------------------------------------------------------------------------
    | Two-factor authentication (email one-time code)
    |--------------------------------------------------------------------------
    | Users holding any of these roles MUST complete 2FA at login. Other users
    | may opt in by enabling `two_factor_enabled` on their account.
    */
    'two_factor' => [
        'required_roles' => ['Super Administrator', 'Administrator'],
        'code_length' => 6,
        'code_ttl_minutes' => (int) env('TWO_FACTOR_CODE_TTL', 10),
        'max_attempts' => (int) env('TWO_FACTOR_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | API token lifetimes (minutes)
    |--------------------------------------------------------------------------
    */
    'tokens' => [
        'customer_ttl' => (int) env('SANCTUM_CUSTOMER_TOKEN_TTL', 60 * 24 * 7),
        'staff_ttl' => (int) env('SANCTUM_STAFF_TOKEN_TTL', 60 * 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | First super administrator (used by SuperAdminSeeder)
    |--------------------------------------------------------------------------
    | Read here (not with env() in the seeder) so it also works when the
    | configuration is cached in production.
    */
    'bootstrap_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'System Administrator'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit redaction
    |--------------------------------------------------------------------------
    | Any key containing one of these fragments is replaced with "[REDACTED]"
    | before it is written to the audit log.
    */
    'audit_redact' => [
        'password', 'token', 'secret', 'key', 'code', 'credential', 'hash', 'otp',
    ],
];
