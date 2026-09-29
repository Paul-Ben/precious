<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PaymentGatewaySettingsController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1  (prefix: /api/v1 - see bootstrap/app.php)
|--------------------------------------------------------------------------
| Every sensitive route is protected server-side by the `permission:`
| middleware. Frontend visibility is never relied upon for security.
*/

Route::get('health', HealthController::class)->name('health');

Route::middleware('throttle:api')->group(function () {

    // ----------------------------------------------------------------- Auth
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [AuthController::class, 'register'])->name('register');
            Route::post('login', [AuthController::class, 'login'])->name('login');
            Route::post('staff/login', [AuthController::class, 'staffLogin'])->name('staff.login');
            Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('password.forgot');
            Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');
        });

        Route::middleware('throttle:two-factor')->group(function () {
            Route::post('two-factor/verify', [AuthController::class, 'verifyTwoFactor'])->name('two-factor.verify');
            Route::post('two-factor/resend', [AuthController::class, 'resendTwoFactor'])->name('two-factor.resend');
        });

        Route::middleware(['auth:sanctum', 'active'])->group(function () {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('password/change', [AuthController::class, 'changePassword'])
                ->middleware('two_factor')
                ->name('password.change');
        });
    });

    // --------------------------------------------------------------- Staff
    Route::middleware(['auth:sanctum', 'active', 'two_factor', 'password.changed', 'staff'])->group(function () {

        // Users
        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view')->name('users.index');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create')->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view')->name('users.show');
        Route::patch('users/{user}', [UserController::class, 'update'])->middleware('permission:users.update')->name('users.update');
        Route::put('users/{user}/roles', [UserController::class, 'syncRoles'])->middleware('permission:roles.assign')->name('users.roles.sync');
        Route::post('users/{user}/suspend', [UserController::class, 'suspend'])->middleware('permission:users.update')->name('users.suspend');
        Route::post('users/{user}/activate', [UserController::class, 'activate'])->middleware('permission:users.update')->name('users.activate');
        Route::post('users/{user}/temporary-password', [UserController::class, 'issueTemporaryPassword'])->middleware('permission:users.update')->name('users.temporary-password');

        // Roles & permissions
        Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view')->name('permissions.index');
        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view')->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create')->name('roles.store');
        Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view')->name('roles.show');
        Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update')->name('roles.update');
        Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('permission:roles.update')->name('roles.permissions.sync');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete')->name('roles.destroy');

        // Settings - payment gateways
        Route::prefix('settings/payment-gateways')->name('settings.payment-gateways.')
            ->middleware('permission:settings.payment_gateways.manage')
            ->group(function () {
                Route::get('/', [PaymentGatewaySettingsController::class, 'index'])->name('index');
                Route::get('{gateway}', [PaymentGatewaySettingsController::class, 'show'])->name('show');
                Route::patch('{gateway}', [PaymentGatewaySettingsController::class, 'update'])->name('update');
                Route::post('{gateway}/test', [PaymentGatewaySettingsController::class, 'test'])->name('test');
            });

        // Audit
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('audit-logs.index');
    });
});
