<?php

namespace App\Providers;

use App\Domain\Property\HotelSettings;
use App\Models\BarTab;
use App\Models\PersonalAccessToken;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        // One settings cache per request / job.
        $this->app->scoped(HotelSettings::class);
        $this->app->scoped(Property::CURRENT, fn () => Property::query()->orderBy('id')->firstOrFail());
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Short, stable names for polymorphic "what was paid for" columns.
        Relation::morphMap(['reservation' => Reservation::class, 'bar_tab' => BarTab::class]);

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

        // Public website.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(120)->by('public:'.$request->ip()));
        RateLimiter::for('booking', fn (Request $request) => Limit::perMinute(10)->by('booking:'.$request->ip()));
        RateLimiter::for('booking-lookup', fn (Request $request) => Limit::perMinute(20)->by('lookup:'.$request->ip()));
        RateLimiter::for('payments', fn (Request $request) => Limit::perMinute(10)->by('pay:'.$request->ip()));
        // Gateways retry on failure; generous but bounded.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by('webhook:'.$request->ip()));

        RateLimiter::for('two-factor', function (Request $request) {
            return [
                Limit::perMinute(10)->by('2fa-ip:'.$request->ip()),
                Limit::perMinute(6)->by('2fa-challenge:'.$request->input('challenge_id')),
            ];
        });
    }
}
