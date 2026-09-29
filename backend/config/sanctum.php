<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [
    /*
    | This API uses bearer tokens only (the Next.js server holds the token in an
    | httpOnly cookie). Stateful/cookie SPA auth is therefore disabled.
    */
    'stateful' => [],

    'guard' => [],

    /*
    | Default expiry (minutes). Tokens are additionally issued with an explicit
    | expires_at per user type - see config/security.php.
    */
    'expiration' => (int) env('SANCTUM_CUSTOMER_TOKEN_TTL', 60 * 24 * 7),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'hp_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
];
