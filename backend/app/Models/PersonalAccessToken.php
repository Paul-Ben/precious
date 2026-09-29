<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'two_factor_confirmed',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'two_factor_confirmed' => 'boolean',
        ]);
    }
}
