<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Private broadcast channels (Laravel Reverb)
|--------------------------------------------------------------------------
| Authorised at POST /api/v1/broadcasting/auth (Sanctum bearer token via the
| Next.js BFF). Only live "something changed" hints are pushed; screens then
| refetch through the normal permission-checked API.
*/

// Bar queue and waiter screens.
Broadcast::channel('bar', fn (User $user) => $user->isActive() && $user->canAny([
    'bar.orders.view', 'bar.orders.prepare', 'bar.orders.create',
]));
