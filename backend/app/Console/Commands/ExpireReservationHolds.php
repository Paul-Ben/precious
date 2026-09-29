<?php

namespace App\Console\Commands;

use App\Domain\Reservations\ReservationService;
use Illuminate\Console\Command;

class ExpireReservationHolds extends Command
{
    protected $signature = 'reservations:expire-holds';

    protected $description = 'Expire unpaid reservations whose hold time has passed and release their rooms';

    public function handle(ReservationService $reservations): int
    {
        $count = $reservations->expireStaleHolds();
        $this->info("Expired {$count} reservation(s).");

        return self::SUCCESS;
    }
}
