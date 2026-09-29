<?php

use App\Models\TwoFactorChallenge;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs. Run locally with `php artisan schedule:work`; in production
| a dedicated Railway service runs `php artisan schedule:work`.
*/

// Remove expired Sanctum tokens (keeps the table small).
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Remove used/expired 2FA challenges older than a day.
Schedule::command('model:prune', ['--model' => [TwoFactorChallenge::class]])->daily();

// Remove expired password reset tokens.
Schedule::command('auth:clear-resets')->everyFifteenMinutes();

// Release rooms held by unpaid reservations (ASSUMPTIONS P5: 30-minute hold).
Schedule::command('reservations:expire-holds')->everyMinute()->withoutOverlapping();

// Arrivals that never checked in become NO_SHOW (ASSUMPTIONS P7).
Schedule::command('reservations:mark-no-shows')->dailyAt('23:59')->withoutOverlapping();

// Settle online payments whose redirect and webhook never arrived (spec §15).
Schedule::command('payments:reconcile')->everyTenMinutes()->withoutOverlapping();
