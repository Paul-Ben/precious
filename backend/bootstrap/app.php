<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException as PermissionUnauthorizedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->api(prepend: [
            AssignRequestId::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'active' => EnsureAccountActive::class,
            'password.changed' => EnsurePasswordChanged::class,
            'two_factor' => EnsureTwoFactorConfirmed::class,
            'staff' => EnsureStaff::class,
            'permission' => RequirePermission::class,
        ]);

        // API only: never redirect unauthenticated requests to a login page.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->dontReport([BusinessRuleException::class]);

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error(
                    'Validation failed.', 422, 'VALIDATION_FAILED', $e->errors()
                ),
                $e instanceof BusinessRuleException => ApiResponse::error(
                    $e->getMessage(), $e->status, $e->errorCode, null, $e->context
                ),
                $e instanceof AuthenticationException => ApiResponse::error(
                    'Unauthenticated.', 401, 'UNAUTHENTICATED'
                ),
                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException,
                $e instanceof PermissionUnauthorizedException => ApiResponse::error(
                    'You do not have permission to perform this action.', 403, 'FORBIDDEN'
                ),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    'The requested resource was not found.', 404, 'NOT_FOUND'
                ),
                $e instanceof MethodNotAllowedHttpException => ApiResponse::error(
                    'Method not allowed.', 405, 'METHOD_NOT_ALLOWED'
                ),
                $e instanceof ThrottleRequestsException => ApiResponse::error(
                    'Too many requests. Please slow down.', 429, 'TOO_MANY_REQUESTS', null,
                    ['retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 60)]
                )->withHeaders($e->getHeaders()),
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    $e->getMessage() !== '' ? $e->getMessage() : 'Request could not be processed.',
                    $e->getStatusCode(),
                    'HTTP_'.$e->getStatusCode()
                )->withHeaders($e->getHeaders()),
                default => ApiResponse::error(
                    config('app.debug') ? $e->getMessage() : 'An unexpected error occurred. Please try again.',
                    500,
                    'SERVER_ERROR',
                    null,
                    config('app.debug') ? ['exception' => $e::class] : []
                ),
            };
        });
    })->create();
