<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('permission:users.view')  or  'permission:a|b' (any of).
 *
 * Checks go through the Gate, so the Super Administrator bypass and Spatie's
 * permission registrar both apply. Permissions are re-read on every request,
 * so revoking a permission takes effect immediately.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error('Unauthenticated.', 401, 'UNAUTHENTICATED');
        }

        if (! $user->canAny(explode('|', $permissions))) {
            return ApiResponse::error('You do not have permission to perform this action.', 403, 'FORBIDDEN');
        }

        return $next($request);
    }
}
