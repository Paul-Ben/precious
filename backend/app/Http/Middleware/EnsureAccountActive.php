<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            $user->currentAccessToken()?->delete();

            return ApiResponse::error('Your account has been suspended.', 403, 'ACCOUNT_SUSPENDED');
        }

        return $next($request);
    }
}
