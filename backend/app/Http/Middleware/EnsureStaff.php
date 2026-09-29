<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isStaff()) {
            return ApiResponse::error('This area is for staff only.', 403, 'FORBIDDEN');
        }

        return $next($request);
    }
}
