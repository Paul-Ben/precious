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
