<?php

namespace App\Domain\Identity;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TokenIssuer
{
    public function __construct(private readonly Request $request) {}

    /**
     * @return array{token: string, token_type: string, expires_at: string}
     */
    public function issue(User $user, bool $twoFactorConfirmed): array
    {
        $ttl = $user->isStaff()
            ? config('security.tokens.staff_ttl')
            : config('security.tokens.customer_ttl');

        $expiresAt = now()->addMinutes((int) $ttl);

        $newToken = $user->createToken($user->type->value.'-session', ['*'], $expiresAt);

        $newToken->accessToken->forceFill([
            'two_factor_confirmed' => $twoFactorConfirmed,
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
        ])->save();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $this->request->ip(),
        ])->saveQuietly();

        return [
            'token' => $newToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
