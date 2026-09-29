<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        DB::prohibitDestructiveCommands($this->app->isProduction());

        $this->configurePasswords();
        $this->configureAuthorization();
        $this->configureRateLimiting();
    }

    private function configurePasswords(): void
    {
        Password::defaults(function () {
            $rule = Password::min(10)->letters()->mixedCase()->numbers()->symbols();

            // Checks haveibeenpwned.com (k-anonymity). Production only.
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }

    private function configureAuthorization(): void
    {
        // Super Administrators pass every permission check.
        Gate::before(function (User $user) {
            return $user->hasRole(config('security.super_admin_role')) ? true : null;
        });
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Login, registration and password reset: per IP and per e-mail.
        RateLimiter::for('auth', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(20)->by('auth-ip:'.$request->ip()),
                Limit::perMinute(5)->by('auth-email:'.$email.'|'.$request->ip()),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return [
                Limit::perMinute(10)->by('2fa-ip:'.$request->ip()),
                Limit::perMinute(6)->by('2fa-challenge:'.$request->input('challenge_id')),
            ];
        });
    }
}
