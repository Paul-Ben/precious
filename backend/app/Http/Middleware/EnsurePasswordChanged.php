<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff created with a temporary password must set their own password before
 * using anything else.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return ApiResponse::error(
                'You must change your temporary password before continuing.',
                403,
                'PASSWORD_CHANGE_REQUIRED'
            );
        }

        return $next($request);
    }
}
