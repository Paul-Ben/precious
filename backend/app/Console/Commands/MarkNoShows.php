<?php

namespace App\Console\Commands;

use App\Domain\Property\HotelSettings;
use App\Domain\Reservations\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarkNoShows extends Command
{
    protected $signature = 'reservations:mark-no-shows {--date= : Arrival date (Y-m-d); defaults to today in the hotel time zone}';

    protected $description = 'Mark confirmed reservations that did not check in on their arrival day as NO_SHOW';

    public function handle(ReservationService $reservations, HotelSettings $settings): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::createFromFormat('!Y-m-d', $this->option('date'))
            : $settings->today();

        $count = $reservations->markNoShows($date);
        $this->info("Marked {$count} reservation(s) as no-show.");

        return self::SUCCESS;
    }
}
