<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Users that require 2FA (admins, or anyone who opted in) may only use tokens
 * that were issued after a successful 2FA challenge.
 */
class EnsureTwoFactorConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->requiresTwoFactor()) {
            $token = $user->currentAccessToken();

            if (! $token instanceof PersonalAccessToken || ! $token->two_factor_confirmed) {
                return ApiResponse::error(
                    'Two-factor verification is required. Please sign in again.',
                    403,
                    'TWO_FACTOR_REQUIRED'
                );
            }
        }

        return $next($request);
    }
}
